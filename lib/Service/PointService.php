<?php

declare(strict_types=1);


/**
 * Nextcloud - Backup
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Backup\Service;


use ArtificialOwl\MySmallPhpTools\Traits\Nextcloud\nc22\TNC22Logger;
use ArtificialOwl\MySmallPhpTools\Traits\TStringTools;
use OC;
use OC\Files\AppData\Factory;
use OCA\Backup\AppInfo\Application;
use OCA\Backup\Db\PointRequest;
use OCA\Backup\Exceptions\ArchiveCreateException;
use OCA\Backup\Exceptions\ArchiveDeleteException;
use OCA\Backup\Exceptions\ArchiveNotFoundException;
use OCA\Backup\Exceptions\BackupAppCopyException;
use OCA\Backup\Exceptions\BackupScriptNotFoundException;
use OCA\Backup\Exceptions\SqlDumpException;
use OCA\Backup\Model\RestoringData;
use OCA\Backup\Model\RestoringPoint;
use OCA\Backup\SqlDump\SqlDumpMySQL;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Util;


/**
 * Class PointService
 *
 * @package OCA\Backup\Service
 */
class PointService {


	use TNC22Logger;
	use TStringTools;


	const NOBACKUP_FILE = '.nobackup';
	const METADATA_FILE = 'metadata.json';
	const SQL_DUMP_FILE = 'backup_sql';


	/** @var PointRequest */
	private $pointRequest;

	/** @var ConfigService */
	private $configService;

	/** @var ArchiveService */
	private $archiveService;

	/** @var IAppData */
	private $appData;


	/**
	 * PointService constructor.
	 *
	 * @param PointRequest $pointRequest
	 * @param ArchiveService $archiveService
	 * @param ConfigService $configService
	 */
	public function __construct(
		PointRequest $pointRequest,
		ArchiveService $archiveService,
		ConfigService $configService
	) {
		$this->pointRequest = $pointRequest;
		$this->archiveService = $archiveService;
		$this->configService = $configService;

		if (class_exists(OC::class)) {
			/** @var Factory $factory */
			$factory = OC::$server->get(Factory::class);
			$this->appData = $factory->get(Application::APP_ID);
		}

		$this->setup('app', 'backup');
	}


	/**
	 * @param bool $complete
	 *
	 * @return RestoringPoint
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws ArchiveCreateException
	 * @throws ArchiveDeleteException
	 * @throws ArchiveNotFoundException
	 * @throws BackupAppCopyException
	 * @throws BackupScriptNotFoundException
	 * @throws SqlDumpException
	 */
	public function create(bool $complete): RestoringPoint {
		$point = $this->initRestoringPoint($complete);
		$this->archiveService->copyApp($point);

//		$backup->setEncryptionKey('12345');
		$this->archiveService->createChunks($point);
		$this->backupSql($point);
		$this->generateMetadata($point);

		return $point;
	}


	/**
	 * @param bool $complete
	 *
	 * @return RestoringPoint
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function initRestoringPoint(bool $complete): RestoringPoint {
		$this->initBackupFS();

		$point = new RestoringPoint();
		$point->setDate(time());
		$point->setId(date("YmdHis", $point->getDate()) . '-' . $this->token());
		$point->setNC(Util::getVersion());

		$folder = $this->appData->newFolder('/' . $point->getId());
		$temp = $folder->newFile(self::METADATA_FILE);
		$temp->putContent('');

		$point->setBaseFolder($folder);

		$this->addingRestoringData($point, $complete);

		return $point;
	}


	/**
	 * @param RestoringPoint $point
	 * @param bool $complete
	 */
	private function addingRestoringData(RestoringPoint $point, bool $complete): void {
		if ($complete) {
			$point->addRestoringData(new RestoringData(RestoringData::ROOT_DATA, '', RestoringData::DATA));
		} else {
			$this->addIncrementedFiles($point);
		}

		$point->addRestoringData(
			new RestoringData(
				RestoringData::ROOT_NEXTCLOUD,
				'apps/',
				RestoringData::APPS
			)
		);
		$point->addRestoringData(new RestoringData(RestoringData::FILE_CONFIG, '', RestoringData::CONFIG));

		$this->addCustomApps($point);
	}


	/**
	 * @param RestoringPoint $point
	 */
	private function addCustomApps(RestoringPoint $point) {
		$customApps = $this->configService->getSystemValue('apps_paths');
		if (!is_array($customApps)) {
			return;
		}

		foreach ($customApps as $app) {
			if (!is_array($app) || !array_key_exists('path', $app)) {
				continue;
			}

			$name = 'apps_' . $this->uuid(8);
			$point->addRestoringData(new RestoringData(RestoringData::ROOT_DISK, $app['path'], $name));
		}
	}


	/**
	 * @param RestoringPoint $restoringPoint
	 */
	private function addIncrementedFiles(RestoringPoint $restoringPoint): void {
		// TODO:
		// - get last complete RestoringPoint
		// - get list of incremental backup
		// - generate metadata
		// - get list of user files to add to the backup
		// - get list of non-user files to add to the backup
	}


	/**
	 * @param RestoringPoint $point
	 *
	 * @throws ArchiveCreateException
	 * @throws ArchiveNotFoundException
	 * @throws SqlDumpException
	 */
	private function backupSql(RestoringPoint $point) {
		$content = $this->generateSqlDump();

		$data = new RestoringData(RestoringData::SQL_DUMP, '', 'sqldump');
		$this->archiveService->createContentChunk(
			$point,
			$data,
			self::SQL_DUMP_FILE,
			$content
		);

		$point->addRestoringData($data);
	}


	/**
	 * @return string
	 * @throws SqlDumpException
	 */
	private function generateSqlDump(): string {
		$data = [
			'dbname' => $this->configService->getSystemValue('dbname'),
			'dbhost' => $this->configService->getSystemValue('dbhost'),
			'dbport' => $this->configService->getSystemValue('dbport'),
			'dbuser' => $this->configService->getSystemValue('dbuser'),
			'dbpassword' => $this->configService->getSystemValue('dbpassword')
		];

		$sqlDump = new SqlDumpMySQL();

		return $sqlDump->export($data);
	}


	/**
	 * @param RestoringPoint $point
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function generateMetadata(RestoringPoint $point) {
		if (!$point->hasBaseFolder()) {
			return;
		}

		$folder = $point->getBaseFolder();

		$json = $folder->getFile(self::METADATA_FILE);
		$json->putContent(json_encode($point, JSON_PRETTY_PRINT));
	}


	/**
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 */
	private function initBackupFS() {
		$path = '/';

		try {
			$folder = $this->appData->getFolder($path);
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder($path);
		}

		$temp = $folder->newFile(self::NOBACKUP_FILE);
		$temp->putContent('');
	}

}

