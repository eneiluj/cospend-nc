<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cospend\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use SensitiveParameter;

/**
 * Class ShareMapper
 *
 * @package OCA\Cospend\Db
 *
 * @method Share mapRowToEntity(array $row)
 * @method Share findEntity(IQueryBuilder $query)
 * @method Share[] findEntities(IQueryBuilder $query)
 * @template-extends QBMapper<Share>
 */
class ShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'cospend_shares', Share::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getShareById(int $id): Share {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getLinkOrFederatedShareByToken(
		#[SensitiveParameter]
		string $token,
	): Share {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)));

		$or = $qb->expr()->orx();
		$or->add($qb->expr()->eq('type', $qb->createNamedParameter(Share::TYPE_FEDERATION, IQueryBuilder::PARAM_STR)));
		$or->add($qb->expr()->eq('type', $qb->createNamedParameter(Share::TYPE_PUBLIC_LINK, IQueryBuilder::PARAM_STR)));
		$qb->andWhere($or);

		return $this->findEntity($qb);
	}

	/**
	 * @param string $projectId
	 * @param string $userCloudId
	 * @param string|null $token
	 * @return Share
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getFederatedShareByProjectIdAndUserCloudId(
		string $projectId,
		string $userCloudId,
		#[SensitiveParameter]
		?string $token = null,
	): Share {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('user_cloud_id', $qb->createNamedParameter($userCloudId, IQueryBuilder::PARAM_STR)));

		if ($token !== null) {
			$qb->andWhere($qb->expr()->eq('userid', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)));
		}

		return $this->findEntity($qb);
	}

	/**
	 * @param IUser $user
	 * @return Share[]
	 */
	public function getSharesForUser(IUser $user): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($user->getUID(), IQueryBuilder::PARAM_STR)));

		return $this->findEntities($qb);
	}

	/**
	 * @param string $projectId
	 * @param string|null $type
	 * @return Share[]
	 * @throws Exception
	 */
	public function getSharesOfProject(string $projectId, ?string $type = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR)));

		if ($type !== null) {
			$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)));
		}

		return $this->findEntities($qb);
	}
}
