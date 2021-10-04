<?php

declare(strict_types=1);


/**
 * Nextcloud - Backup now. Restore later.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021, Maxence Lange <maxence@artificial-owl.com>
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


namespace OCA\Backup\Listeners;


use ArtificialOwl\MySmallPhpTools\Traits\Nextcloud\nc23\TNC23Logger;
use OCA\Backup\AppInfo\Application;
use OCA\Backup\Model\ChangedFile;
use OCA\Backup\Service\FilesService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;


/**
 * Class NodeEvent
 *
 * @package OCA\Backup\Listeners
 */
class NodeEvent implements IEventListener {


	use TNC23Logger;


	/** @var FilesService */
	private $filesService;


	/**
	 * NodeEvent constructor.
	 *
	 * @param FilesService $filesService
	 */
	public function __construct(FilesService $filesService) {
		$this->filesService = $filesService;

		$this->setup('app', Application::APP_ID);
	}


	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		$node = null;
		if ($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent) {
			$node = $event->getNode();
		}

		if ($event instanceof NodeRenamedEvent) {
			$node = $event->getTarget();
		}

		if (is_null($node)) {
			return;
		}

		$file = new ChangedFile($node->getPath());
		$this->filesService->changedFile($file);
	}

}

