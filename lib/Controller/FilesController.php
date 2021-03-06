<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
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

namespace OCA\IOSHelper\Controller;

use OCA\IOSHelper\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\IRootFolder;
use OCP\IPreview;
use OCP\IRequest;
use OCP\ITagManager;
use OCP\ITags;

class FilesController extends Controller {

	/** @var IRootFolder */
	private $rootFolder;
	/** @var string */
	private $userId;
	/**
	 * @var IPreview
	 */
	private $previewManager;
	/**
	 * @var ITagManager
	 */
	private $tagManager;

	public function __construct(IRequest $request,
								IRootFolder $rootFolder,
								string $userId,
								IPreview $previewManager,
								ITagManager $tagManager) {
		parent::__construct(Application::APP_ID, $request);
		$this->rootFolder = $rootFolder;
		$this->userId = $userId;
		$this->previewManager = $previewManager;
		$this->tagManager = $tagManager;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function list(string $dir, int $offset = 0, int $limit = 250, string $sort = 'name', string $order = 'asc'): JSONResponse {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);

		$nodes = $this->getNodes($userFolder, $dir);
		$nodes = $this->sort($nodes, $sort, $order);
		$nodes = $this->offsetAndLimit($nodes, $offset, $limit);

		$tagger = $this->tagManager->load('files');

		return $this->formatNodes($userFolder, $nodes, $tagger);
	}

	private function getNodes(Folder $userFolder, string $dir): iterable {
		$folder = $userFolder->get($dir);

		//TODO error handling

		if ($folder instanceof File) {
			return [];
		}

		yield $folder;

		/** @var Folder $folder */
		$nodes = $folder->getDirectoryListing();
		yield from $nodes;
	}

	private function sort(iterable $nodes, string $sort, string $order): iterable {

		$sortName = function(Node $a, Node $b) use ($sort, $order): int {
			// Sort
			if ($sort === 'mtime') {
				$result = $a->getMTime() - $b->getMTime();
			} else if ($sort === 'size') {
				$result = $a->getSize() - $b->getSize();
			} else if ($sort === 'mimetype') {
				$result = strcmp($a->getMimetype(), $b->getMimetype());
			} else  {
				$result = strcmp($a->getName(), $b->getName());
			}

			// Flip on desc
			if ($order === 'desc') {
				$result = -$result;
			}

			return $result;
		};

		$nodeArr = iterator_to_array($nodes);
		uasort($nodeArr, $sortName);

		foreach ($nodeArr as $node) {
			yield $node;
		}
	}

	private function offsetAndLimit(iterable $nodes, int $offset, int $limit): iterable {
		$i = 0;
		$j = 0;
		foreach ($nodes as $node) {
			if ($i < $offset) {
				$i++;
				continue;
			}
			if ($j >= $limit) {
				break;
			}
			$j++;

			yield $node;
		}
	}

	private function formatNodes(Folder $userFolder, iterable $nodes, ITags $tagger): JSONResponse {
		$result = [];

		foreach ($nodes as $node) {
			$tags = $tagger->getTagsForObjects([$node->getId()]);
			$favorite = false;
			if (isset($tags[$node->getId()])) {
				$favorite = array_search('_$!<Favorite>!$_', $tags[$node->getId()], true) !== false;
			}
			$result[] = $this->formatNode($userFolder, $node, $favorite);
		}

		return new JSONResponse($result);
	}

	private function formatNode(Folder $userFolder, Node $node, bool $favorite) {
		return [
			'path' => $userFolder->getRelativePath($node->getPath()),
			'name' => $node->getName(),
			'mimetype' => $node->getMimetype(),
			'etag' => $node->getEtag(),
			'fileId' => $node->getId(),
			'size' => $node->getSize(),
			'hasPreview' => $this->previewManager->isAvailable($node),
			'favorite' => $favorite,
			'modificationDate' => $node->getMTime(),
			'directory' => $node instanceof Folder,
			'permissions' => $node->getPermissions(),
			'ocId' => $this->getOcId($node->getId()),
		];
	}

	private function getOcId(int $id): string {
		$instanceId = \OC_Util::getInstanceId();
		$id = sprintf('%08d', $id);
		return $id . $instanceId;
	}
}
