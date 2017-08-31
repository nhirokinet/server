<?php
/**
 * @copyright Copyright (c) 2017 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
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

namespace OC\Collaboration\Collaborators;

use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Share;

class GroupPlugin implements ISearchPlugin {
	protected $shareeEnumeration;
	protected $shareWithGroupOnly;

	/** @var IGroupManager */
	private $groupManager;
	/** @var IConfig */
	private $config;
	/** @var IUserSession */
	private $userSession;

	public function __construct(IConfig $config, IGroupManager $groupManager, IUserSession $userSession) {
		$this->groupManager = $groupManager;
		$this->config = $config;
		$this->userSession = $userSession;

		$this->shareeEnumeration = $this->config->getAppValue('core', 'shareapi_allow_share_dialog_user_enumeration', 'yes') === 'yes';
		$this->shareWithGroupOnly = $this->config->getAppValue('core', 'shareapi_only_share_with_group_members', 'no') === 'yes';
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult) {
		$hasMoreResults = false;
		$result = ['wide' => [], 'exact' => []];

		$groups = $this->groupManager->search($search, $limit, $offset);
		$groupIds = array_map(function (IGroup $group) { return $group->getGID(); }, $groups);

		if (!$this->shareeEnumeration || sizeof($groups) < $limit) {
			$hasMoreResults = true;
		}

		$userGroups =  [];
		if (!empty($groups) && $this->shareWithGroupOnly) {
			// Intersect all the groups that match with the groups this user is a member of
			$userGroups = $this->groupManager->getUserGroups($this->userSession->getUser());
			$userGroups = array_map(function (IGroup $group) { return $group->getGID(); }, $userGroups);
			$groupIds = array_intersect($groupIds, $userGroups);
		}

		$lowerSearch = strtolower($search);
		foreach ($groups as $group) {
			// FIXME: use a more efficient approach
			$gid = $group->getGID();
			if (!in_array($gid, $groupIds)) {
				continue;
			}
			if (strtolower($gid) === $lowerSearch || strtolower($group->getDisplayName()) === $lowerSearch) {
				$result['exact'][] = [
					'label' => $group->getDisplayName(),
					'value' => [
						'shareType' => Share::SHARE_TYPE_GROUP,
						'shareWith' => $gid,
					],
				];
			} else {
				$result['wide'][] = [
					'label' => $group->getDisplayName(),
					'value' => [
						'shareType' => Share::SHARE_TYPE_GROUP,
						'shareWith' => $gid,
					],
				];
			}
		}

		if ($offset === 0 && empty($result['exact'])) {
			// On page one we try if the search result has a direct hit on the
			// user id and if so, we add that to the exact match list
			$group = $this->groupManager->get($search);
			if ($group instanceof IGroup && (!$this->shareWithGroupOnly || in_array($group->getGID(), $userGroups))) {
				array_push($result['exact'], [
					'label' => $group->getDisplayName(),
					'value' => [
						'shareType' => Share::SHARE_TYPE_GROUP,
						'shareWith' => $group->getGID(),
					],
				]);
			}
		}

		if (!$this->shareeEnumeration) {
			$result['wide'] = [];
		}

		$searchResult->addResultSet('groups', $result['wide'], $result['exact']);

		return [$result, $hasMoreResults];
	}
}
