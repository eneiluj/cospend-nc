<?php

/**
 * Nextcloud - cospend
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Cospend\Service;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\Cospend\Activity\ActivityManager;
use OCA\Cospend\AppInfo\Application;
use OCA\Cospend\Db\Bill;
use OCA\Cospend\Db\BillMapper;

use OCA\Cospend\Db\BillOwer;
use OCA\Cospend\Db\BillOwerMapper;
use OCA\Cospend\Db\Category;
use OCA\Cospend\Db\CategoryMapper;
use OCA\Cospend\Db\Currency;
use OCA\Cospend\Db\CurrencyMapper;
use OCA\Cospend\Db\Invitation;
use OCA\Cospend\Db\Member;
use OCA\Cospend\Db\MemberMapper;
use OCA\Cospend\Db\PaymentMode;
use OCA\Cospend\Db\PaymentModeMapper;
use OCA\Cospend\Db\ProjectMapper;
use OCA\Cospend\Db\Share;
use OCA\Cospend\Db\ShareMapper;
use OCA\Cospend\Exception\CospendBasicException;
use OCA\Cospend\Federation\BackendNotifier;
use OCA\Cospend\Federation\FederationManager;
use OCA\Cospend\ResponseDefinitions;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;

use OCP\Federation\ICloudIdManager;

use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDateTimeZone;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ISecureRandom;

/**
 * @psalm-import-type CospendFullProjectInfo from ResponseDefinitions
 * @psalm-import-type CospendProjectInfoPlusExtra from ResponseDefinitions
 * @psalm-import-type CospendMember from ResponseDefinitions
 */
class LocalProjectService implements IProjectService {

	public array $defaultCategories;
	public array $defaultPaymentModes;
	private array $hardCodedCategoryNames;
	private ?array $paymentModes = null;

	public function __construct(
		private IL10N $l10n,
		private IConfig $config,
		private IAppConfig $appConfig,
		private ProjectMapper $projectMapper,
		private BillMapper $billMapper,
		private MemberMapper $memberMapper,
		private ShareMapper $shareMapper,
		private CurrencyMapper $currencyMapper,
		private PaymentModeMapper $paymentModeMapper,
		private CategoryMapper $categoryMapper,
		private BillOwerMapper $billOwerMapper,
		private BackendNotifier $backendNotifier,
		private ICloudIdManager $cloudIdManager,
		private ActivityManager $activityManager,
		private IUserManager $userManager,
		private IAppManager $appManager,
		private IGroupManager $groupManager,
		private IDateTimeZone $dateTimeZone,
		private INotificationManager $notificationManager,
		private IDBConnection $db,
		private ISecureRandom $secureRandom,
	) {
		$this->defaultCategories = [
			[
				'name' => $this->l10n->t('Grocery'),
				'icon' => '🛒',
				'color' => '#ffaa00',
			],
			[
				'name' => $this->l10n->t('Bar/Party'),
				'icon' => '🎉',
				'color' => '#aa55ff',
			],
			[
				'name' => $this->l10n->t('Rent'),
				'icon' => '🏠',
				'color' => '#da8733',
			],
			[
				'name' => $this->l10n->t('Bill'),
				'icon' => '🌩',
				'color' => '#4aa6b0',
			],
			[
				'name' => $this->l10n->t('Excursion/Culture'),
				'icon' => '🚸',
				'color' => '#0055ff',
			],
			[
				'name' => $this->l10n->t('Health'),
				'icon' => '💚',
				'color' => '#bf090c',
			],
			[
				'name' => $this->l10n->t('Shopping'),
				'icon' => '🛍',
				'color' => '#e167d1',
			],
			[
				'name' => $this->l10n->t('Restaurant'),
				'icon' => '🍴',
				'color' => '#d0d5e1',
			],
			[
				'name' => $this->l10n->t('Accommodation'),
				'icon' => '🛌',
				'color' => '#5de1a3',
			],
			[
				'name' => $this->l10n->t('Transport'),
				'icon' => '🚌',
				'color' => '#6f2ee1',
			],
			[
				'name' => $this->l10n->t('Sport'),
				'icon' => '🎾',
				'color' => '#69e177',
			],
		];

		$this->defaultPaymentModes = [
			[
				'name' => $this->l10n->t('Credit card'),
				'icon' => '💳',
				'color' => '#FF7F50',
				'old_id' => 'c',
			],
			[
				'name' => $this->l10n->t('Cash'),
				'icon' => '💵',
				'color' => '#556B2F',
				'old_id' => 'b',
			],
			[
				'name' => $this->l10n->t('Check'),
				'icon' => '🎫',
				'color' => '#A9A9A9',
				'old_id' => 'f',
			],
			[
				'name' => $this->l10n->t('Transfer'),
				'icon' => '⇄',
				'color' => '#00CED1',
				'old_id' => 't',
			],
			[
				'name' => $this->l10n->t('Online service'),
				'icon' => '🌎',
				'color' => '#9932CC',
				'old_id' => 'o',
			],
		];

		$this->hardCodedCategoryNames = [
			'-11' => $this->l10n->t('Reimbursement'),
		];
	}

	/**
	 * Get max access level of a given user for a given project
	 *
	 * @param string $userId
	 * @param string $projectId
	 * @return int
	 * @throws \OCP\DB\Exception
	 */
	public function getUserMaxAccessLevel(string $userId, string $projectId): int {
		$userMaxAccessLevel = Application::ACCESS_LEVEL_NONE;
		$dbProject = $this->projectMapper->find($projectId);
		if ($dbProject !== null) {
			// does the user own the project ?
			if ($dbProject->getUserId() === $userId) {
				return Application::ACCESS_LEVEL_ADMIN;
			} else {
				// is the project shared with the user ?
				try {
					$userShare = $this->shareMapper->getShareByProjectAndUser($projectId, $userId, Share::TYPE_USER);
					if ($userShare->getAccessLevel() > $userMaxAccessLevel) {
						$userMaxAccessLevel = $userShare->getAccessLevel();
					}
				} catch (\Throwable $e) {
				}

				// is the project shared with a group containing the user?
				$user = $this->userManager->get($userId);

				$groupShares = $this->shareMapper->getSharesOfProject($projectId, Share::TYPE_GROUP);
				foreach ($groupShares as $groupShare) {
					$groupId = $groupShare->getUserId();
					$accessLevel = $groupShare->getAccessLevel();
					if ($this->groupManager->groupExists($groupId)
						&& $this->groupManager->get($groupId)->inGroup($user)
						&& $accessLevel > $userMaxAccessLevel
					) {
						$userMaxAccessLevel = $accessLevel;
					}
				}

				// are circles enabled and is the project shared with a circle containing the user
				$circlesEnabled = $this->appManager->isEnabledForUser('circles');
				if ($circlesEnabled) {
					$circleShares = $this->shareMapper->getSharesOfProject($projectId, Share::TYPE_CIRCLE);
					foreach ($circleShares as $circleShare) {
						$circleId = $circleShare->getUserId();
						$accessLevel = $circleShare->getAccessLevel();
						if ($this->isUserInCircle($userId, $circleId) && $accessLevel > $userMaxAccessLevel) {
							$userMaxAccessLevel = $accessLevel;
						}
					}
				}
			}
		}

		return $userMaxAccessLevel;
	}

	/**
	 * Get access level of a shared access
	 *
	 * @param string $projectId
	 * @param int $shId
	 * @return int
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getShareAccessLevel(string $projectId, int $shId): int {
		$share = $this->shareMapper->getProjectShareById($projectId, $shId);
		return $share->getAccessLevel();
	}

	/**
	 * Create a project
	 *
	 * @param string $name
	 * @param string $id
	 * @param string|null $contact_email
	 * @param string $userId
	 * @param bool $createDefaultCategories
	 * @param bool $createDefaultPaymentModes
	 * @return array
	 * @throws CospendBasicException
	 * @throws \OCP\DB\Exception
	 */
	public function createProject(
		string $name, string $id, ?string $contact_email, string $userId = '',
		bool $createDefaultCategories = true, bool $createDefaultPaymentModes = true,
	): array {
		$newProject = $this->projectMapper->createProject(
			$name, $id, $contact_email, $this->defaultCategories, $this->defaultPaymentModes,
			$userId, $createDefaultCategories, $createDefaultPaymentModes
		);
		return $newProject->jsonSerialize();
	}

	public function deleteProject(string $projectId): void {
		try {
			$dbProjectToDelete = $this->projectMapper->getById($projectId);
		} catch (DoesNotExistException) {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND, ['error' => $this->l10n->t('Not Found')]);
		}
		$this->projectMapper->deleteBillOwersOfProject($projectId);

		$associatedTableNames = [
			'cospend_bills' => 'project_id',
			'cospend_members' => 'project_id',
			'cospend_shares' => 'project_id',
			'cospend_currencies' => 'project_id',
			'cospend_categories' => 'project_id',
			'cospend_paymentmodes' => 'project_id',
		];

		foreach ($associatedTableNames as $tableName => $projectIdColumn) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($tableName)
				->where(
					$qb->expr()->eq($projectIdColumn, $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
				);
			$qb->executeStatement();
		}

		$this->projectMapper->delete($dbProjectToDelete);
	}

	/**
	 * Get all project data
	 *
	 * @param string $projectId
	 * @return CospendProjectInfoPlusExtra
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getProjectInfo(string $projectId): array {
		try {
			$dbProject = $this->projectMapper->getById($projectId);
		} catch (DoesNotExistException) {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND, ['error' => 'project not found']);
		}
		$dbProjectId = $dbProject->getId();

		$smallStats = $this->getSmallStats($dbProjectId);
		$members = $this->getMembers($dbProjectId, 'lowername');
		$activeMembers = [];
		foreach ($members as $member) {
			if ($member['activated']) {
				$activeMembers[] = $member;
			}
		}
		// compute balances for past bills only
		$balancePastBillsOnly = $this->appConfig->getValueString('cospend', 'balance_past_bills_only', '0') === '1';
		$balance = $this->getBalance($dbProjectId, $balancePastBillsOnly ? time() : null);
		$currencies = $this->getCurrencies($dbProjectId);
		$categories = $this->getCategoriesOrPaymentModes($dbProjectId);
		$paymentModes = $this->getCategoriesOrPaymentModes($dbProjectId, false);
		// get all shares
		$userShares = $this->getUserShares($dbProjectId);
		$groupShares = $this->getGroupShares($dbProjectId);
		$circleShares = $this->getCircleShares($dbProjectId);
		$publicShares = $this->getPublicShares($dbProjectId);
		$federatedShares = $this->getFederatedShares($dbProjectId);
		$shares = array_merge($userShares, $groupShares, $circleShares, $publicShares, $federatedShares);

		$extraProjectInfo = [
			'active_members' => $activeMembers,
			'members' => $members,
			'balance' => $balance,
			'nb_bills' => $smallStats['nb_bills'],
			'total_spent' => $smallStats['total_spent'],
			'nb_trashbin_bills' => $smallStats['nb_trashbin_bills'],
			'shares' => $shares,
			'currencies' => $currencies,
			'categories' => $categories,
			'paymentmodes' => $paymentModes,
		];

		return array_merge($extraProjectInfo, $dbProject->jsonSerialize());
	}

	/**
	 * @param string $projectId
	 * @param string $userId
	 * @return array
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getProjectInfoWithAccessLevel(string $projectId, string $userId): array {
		$projectInfo = $this->getProjectInfo($projectId);
		$projectInfo['myaccesslevel'] = $this->getUserMaxAccessLevel($userId, $projectId);
		return $projectInfo;
	}

	/**
	 * Get number of bills and total spent amount for a given project
	 *
	 * @param string $projectId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	private function getSmallStats(string $projectId): array {
		$totalSpent = 0;
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('SUM(amount)'), 'sum_amount')
			->from('cospend_bills')
			->where(
				$qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			);
		$req = $qb->executeQuery();
		while ($row = $req->fetch()) {
			$totalSpent = (float)$row['sum_amount'];
		}

		return [
			'nb_bills' => $this->billMapper->countBills($projectId, null, null, null, 0),
			'total_spent' => $totalSpent,
			'nb_trashbin_bills' => $this->billMapper->countBills($projectId, null, null, null, 1),
		];
	}

	/**
	 * Get project statistics
	 *
	 * @param string $projectId
	 * @param int|null $tsMin
	 * @param int|null $tsMax
	 * @param int|null $paymentModeId
	 * @param int|null $categoryId
	 * @param float|null $amountMin
	 * @param float|null $amountMax
	 * @param bool $showDisabled
	 * @param int|null $currencyId
	 * @param int|null $payerId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	public function getStatistics(
		string $projectId, ?int $tsMin = null, ?int $tsMax = null,
		?int $paymentModeId = null, ?int $categoryId = null, ?float $amountMin = null, ?float $amountMax = null,
		bool $showDisabled = true, ?int $currencyId = null, ?int $payerId = null,
	): array {
		$timeZone = $this->dateTimeZone->getTimeZone();
		$membersWeight = [];
		$membersNbBills = [];
		$membersBalance = [];
		$membersFilteredBalance = [];
		$membersPaid = [
			'total' => [],
		];
		$membersSpent = [];
		$membersPaidFor = [];

		$currency = null;
		if ($currencyId !== null && $currencyId !== 0) {
			$dbCurrency = $this->currencyMapper->getCurrencyOfProject($projectId, $currencyId);
			$currency = $dbCurrency->jsonSerialize();
		}

		$projectCategories = $this->getCategoriesOrPaymentModes($projectId);
		$projectPaymentModes = $this->getCategoriesOrPaymentModes($projectId, false);

		// get the real global balances with no filters
		$balances = $this->getBalance($projectId);

		$members = $this->getMembers($projectId, 'lowername');
		foreach ($members as $member) {
			$memberId = $member['id'];
			$memberWeight = $member['weight'];
			$membersWeight[$memberId] = $memberWeight;
			$membersNbBills[$memberId] = 0;
			$membersBalance[$memberId] = $balances[$memberId];
			$membersFilteredBalance[$memberId] = 0.0;
			$membersPaid[$memberId] = 0.0;
			$membersSpent[$memberId] = 0.0;
			$membersPaidFor[$memberId] = [];
			foreach ($members as $m) {
				$membersPaidFor[$memberId][$m['id']] = 0.0;
			}
			$membersPaidFor['total'][$memberId] = 0.0;
		}

		// build list of members to display
		$membersToDisplay = [];
		$allMembersIds = [];
		foreach ($members as $member) {
			$memberId = $member['id'];
			$allMembersIds[] = $memberId;
			// only take enabled members or those with non-zero balance
			$mBalance = (float)$membersBalance[$memberId];
			if ($showDisabled || $member['activated'] || $mBalance >= 0.01 || $mBalance <= -0.01) {
				$membersToDisplay[$memberId] = $member;
			}
		}

		// compute stats
		$bills = $this->billMapper->getBillsClassic(
			$projectId, $tsMin, $tsMax, null, $paymentModeId, $categoryId,
			$amountMin, $amountMax, null, null, false, $payerId
		);

		/*
		$firstBillTs = $bills[0]['timestamp'];
		$firstBillDate = DateTime::createFromFormat('U', $firstBillTs);
		$firstBillDate->setTimezone($timeZone);
		$firstBillDate->modify('first day of');
		$firstBillDate->setTime(0, 0);
		$year1 = (int) $firstBillDate->format('Y');
		$month1 = (int) $firstBillDate->format('m');

		$lastBillTs = $bills[count($bills) - 1]['timestamp'];
		$lastBillDate = DateTime::createFromFormat('U', $lastBillTs);
		$lastBillDate->setTimezone($timeZone);
		$lastBillDate->modify('first day of');
		$lastBillDate->setTime(0, 0);
		$year2 = (int) $lastBillDate->format('Y');
		$month2 = (int) $lastBillDate->format('m');

		$fullMonthNumber = (($year2 - $year1) * 12) + ($month2 - $month1 + 1);
		*/

		// compute classic stats
		foreach ($bills as $bill) {
			$payerId = $bill['payer_id'];
			$amount = $bill['amount'];
			$owers = $bill['owers'];

			$membersNbBills[$payerId]++;
			$membersFilteredBalance[$payerId] += $amount;
			$membersPaid[$payerId] += $amount;

			$nbOwerShares = 0.0;
			foreach ($owers as $ower) {
				$owerWeight = $ower['weight'];
				if ($owerWeight === 0.0) {
					$owerWeight = 1.0;
				}
				$nbOwerShares += $owerWeight;
			}
			foreach ($owers as $ower) {
				$owerWeight = $ower['weight'];
				if ($owerWeight === 0.0) {
					$owerWeight = 1.0;
				}
				$owerId = $ower['id'];
				$spent = $amount / $nbOwerShares * $owerWeight;
				$membersFilteredBalance[$owerId] -= $spent;
				$membersSpent[$owerId] += $spent;
				// membersPaidFor
				$membersPaidFor[$payerId][$owerId] += $spent;
				$membersPaidFor['total'][$owerId] += $spent;
			}
		}
		foreach ($members as $member) {
			$memberId = $member['id'];
			$membersPaidFor[$memberId]['total'] = $membersPaid[$memberId];
		}

		// build global stats data
		$statistics = [];
		if ($currency === null) {
			foreach ($membersToDisplay as $memberId => $member) {
				$statistic = [
					'balance' => $membersBalance[$memberId],
					'filtered_balance' => $membersFilteredBalance[$memberId],
					'paid' => $membersPaid[$memberId],
					'spent' => $membersSpent[$memberId],
					'member' => $member
				];
				$statistics[] = $statistic;
			}
		} else {
			foreach ($membersToDisplay as $memberId => $member) {
				$statistic = [
					'balance' => ($membersBalance[$memberId] === 0.0) ? 0 : $membersBalance[$memberId] / $currency['exchange_rate'],
					'filtered_balance' => ($membersFilteredBalance[$memberId] === 0.0) ? 0 : $membersFilteredBalance[$memberId] / $currency['exchange_rate'],
					'paid' => ($membersPaid[$memberId] === 0.0) ? 0 : $membersPaid[$memberId] / $currency['exchange_rate'],
					'spent' => ($membersSpent[$memberId] === 0.0) ? 0 : $membersSpent[$memberId] / $currency['exchange_rate'],
					'member' => $member
				];
				$statistics[] = $statistic;
			}
		}

		// compute monthly member stats
		$memberMonthlyPaidStats = [];
		$memberMonthlySpentStats = [];
		$allMembersKey = 0;
		foreach ($bills as $bill) {
			$payerId = $bill['payer_id'];
			$amount = $bill['amount'];
			$owers = $bill['owers'];
			$date = DateTime::createFromFormat('U', (string)$bill['timestamp']);
			$date->setTimezone($timeZone);
			$month = $date->format('Y-m');
			//////////////// PAID
			// initialize this month
			if (!array_key_exists($month, $memberMonthlyPaidStats)) {
				$memberMonthlyPaidStats[$month] = [];
				foreach ($membersToDisplay as $memberId => $member) {
					$memberMonthlyPaidStats[$month][$memberId] = 0;
				}
				$memberMonthlyPaidStats[$month][$allMembersKey] = 0;
			}

			// add paid amount
			if (array_key_exists($payerId, $membersToDisplay)) {
				$memberMonthlyPaidStats[$month][$payerId] += $amount;
				$memberMonthlyPaidStats[$month][$allMembersKey] += $amount;
			}
			//////////////// SPENT
			// initialize this month
			if (!array_key_exists($month, $memberMonthlySpentStats)) {
				$memberMonthlySpentStats[$month] = [];
				foreach ($membersToDisplay as $memberId => $member) {
					$memberMonthlySpentStats[$month][$memberId] = 0;
				}
				$memberMonthlySpentStats[$month][$allMembersKey] = 0;
			}
			// spent value for all members is the bill amount (like the paid value)
			$memberMonthlySpentStats[$month][$allMembersKey] += $amount;
			// compute number of shares
			$nbOwerShares = 0.0;
			foreach ($owers as $ower) {
				$owerWeight = $ower['weight'];
				if ($owerWeight === 0.0) {
					$owerWeight = 1.0;
				}
				$nbOwerShares += $owerWeight;
			}
			// compute how much each ower has spent
			foreach ($owers as $ower) {
				$owerWeight = $ower['weight'];
				if ($owerWeight === 0.0) {
					$owerWeight = 1.0;
				}
				$owerId = $ower['id'];
				$spent = $amount / $nbOwerShares * $owerWeight;
				if (array_key_exists($owerId, $membersToDisplay)) {
					$memberMonthlySpentStats[$month][$owerId] += $spent;
				}
			}
		}
		// monthly paid and spent average
		$averageKey = $this->l10n->t('Average per month');
		// number of months with actual bills
		$nbMonth = count(array_keys($memberMonthlyPaidStats));
		$realMonths = array_keys($memberMonthlyPaidStats);
		if ($nbMonth > 0) {
			////////////////////// PAID
			$averagePaidStats = [];
			foreach ($membersToDisplay as $memberId => $member) {
				$sum = 0;
				foreach ($memberMonthlyPaidStats as $month => $mStat) {
					$sum += $memberMonthlyPaidStats[$month][$memberId];
				}
				$averagePaidStats[$memberId] = $sum / $nbMonth;
			}
			// average for all members
			$sum = 0;
			foreach ($memberMonthlyPaidStats as $month => $mStat) {
				$sum += $memberMonthlyPaidStats[$month][$allMembersKey];
			}
			$averagePaidStats[$allMembersKey] = $sum / $nbMonth;

			$memberMonthlyPaidStats[$averageKey] = $averagePaidStats;
			////////////////////// SPENT
			$averageSpentStats = [];
			foreach ($membersToDisplay as $memberId => $member) {
				$sum = 0;
				foreach ($memberMonthlySpentStats as $month => $mStat) {
					$sum += $memberMonthlySpentStats[$month][$memberId];
				}
				$averageSpentStats[$memberId] = $sum / $nbMonth;
			}
			// average for all members
			$sum = 0;
			foreach ($memberMonthlySpentStats as $month => $mStat) {
				$sum += $memberMonthlySpentStats[$month][$allMembersKey];
			}
			$averageSpentStats[$allMembersKey] = $sum / $nbMonth;

			$memberMonthlySpentStats[$averageKey] = $averageSpentStats;
		}
		// convert if necessary
		if ($currency !== null) {
			foreach ($memberMonthlyPaidStats as $month => $mStat) {
				foreach ($mStat as $mid => $val) {
					$memberMonthlyPaidStats[$month][$mid] = ($memberMonthlyPaidStats[$month][$mid] === 0.0)
						? 0
						: $memberMonthlyPaidStats[$month][$mid] / $currency['exchange_rate'];
				}
			}
			foreach ($memberMonthlySpentStats as $month => $mStat) {
				foreach ($mStat as $mid => $val) {
					$memberMonthlySpentStats[$month][$mid] = ($memberMonthlySpentStats[$month][$mid] === 0.0)
						? 0
						: $memberMonthlySpentStats[$month][$mid] / $currency['exchange_rate'];
				}
			}
		}
		// compute category and payment mode stats
		$categoryStats = [];
		$paymentModeStats = [];
		foreach ($bills as $bill) {
			// category
			$billCategoryId = $bill['categoryid'];
			if (!array_key_exists(strval($billCategoryId), $this->hardCodedCategoryNames) &&
				!array_key_exists(strval($billCategoryId), $projectCategories)
			) {
				$billCategoryId = 0;
			}
			$amount = $bill['amount'];
			if (!array_key_exists($billCategoryId, $categoryStats)) {
				$categoryStats[$billCategoryId] = 0;
			}
			$categoryStats[$billCategoryId] += $amount;

			// payment mode
			$paymentModeId = $bill['paymentmodeid'];
			if (!array_key_exists(strval($paymentModeId), $projectPaymentModes)) {
				$paymentModeId = 0;
			}
			$amount = $bill['amount'];
			if (!array_key_exists($paymentModeId, $paymentModeStats)) {
				$paymentModeStats[$paymentModeId] = 0;
			}
			$paymentModeStats[$paymentModeId] += $amount;
		}
		// convert if necessary
		if ($currency !== null) {
			foreach ($categoryStats as $catId => $val) {
				$categoryStats[$catId] = ($val === 0.0) ? 0 : $val / $currency['exchange_rate'];
			}
			foreach ($paymentModeStats as $pmId => $val) {
				$paymentModeStats[$pmId] = ($val === 0.0) ? 0 : $val / $currency['exchange_rate'];
			}
		}
		// compute category per member stats
		$categoryMemberStats = [];
		foreach ($bills as $bill) {
			$payerId = $bill['payer_id'];
			$billCategoryId = $bill['categoryid'];
			if (!array_key_exists(strval($billCategoryId), $this->hardCodedCategoryNames) &&
				!array_key_exists(strval($billCategoryId), $projectCategories)
			) {
				$billCategoryId = 0;
			}
			$amount = $bill['amount'];
			if (!array_key_exists($billCategoryId, $categoryMemberStats)) {
				$categoryMemberStats[$billCategoryId] = [];
				foreach ($membersToDisplay as $memberId => $member) {
					$categoryMemberStats[$billCategoryId][$memberId] = 0;
				}
			}
			if (array_key_exists($payerId, $membersToDisplay)) {
				$categoryMemberStats[$billCategoryId][$payerId] += $amount;
			}
		}
		// convert if necessary
		if ($currency !== null) {
			foreach ($categoryMemberStats as $catId => $mStat) {
				foreach ($mStat as $mid => $val) {
					$categoryMemberStats[$catId][$mid] = ($val === 0.0) ? 0 : $val / $currency['exchange_rate'];
				}
			}
		}
		// compute category/payment mode per month stats
		$categoryMonthlyStats = [];
		$paymentModeMonthlyStats = [];
		foreach ($bills as $bill) {
			$amount = $bill['amount'];
			$date = DateTime::createFromFormat('U', (string)$bill['timestamp']);
			$date->setTimezone($timeZone);
			$month = $date->format('Y-m');

			// category
			$billCategoryId = $bill['categoryid'];
			if (!array_key_exists($billCategoryId, $categoryMonthlyStats)) {
				$categoryMonthlyStats[$billCategoryId] = [];
			}
			if (!array_key_exists($month, $categoryMonthlyStats[$billCategoryId])) {
				$categoryMonthlyStats[$billCategoryId][$month] = 0;
			}
			$categoryMonthlyStats[$billCategoryId][$month] += $amount;

			// payment mode
			$paymentModeId = $bill['paymentmodeid'];
			if (!array_key_exists($paymentModeId, $paymentModeMonthlyStats)) {
				$paymentModeMonthlyStats[$paymentModeId] = [];
			}
			if (!array_key_exists($month, $paymentModeMonthlyStats[$paymentModeId])) {
				$paymentModeMonthlyStats[$paymentModeId][$month] = 0;
			}
			$paymentModeMonthlyStats[$paymentModeId][$month] += $amount;
		}
		// average per month
		foreach ($categoryMonthlyStats as $catId => $monthValues) {
			$sum = 0;
			foreach ($monthValues as $month => $value) {
				$sum += $value;
			}
			$avg = $sum / $nbMonth;
			$categoryMonthlyStats[$catId][$averageKey] = $avg;
		}
		foreach ($paymentModeMonthlyStats as $pmId => $monthValues) {
			$sum = 0;
			foreach ($monthValues as $month => $value) {
				$sum += $value;
			}
			$avg = $sum / $nbMonth;
			$paymentModeMonthlyStats[$pmId][$averageKey] = $avg;
		}
		// convert if necessary
		if ($currency !== null) {
			foreach ($categoryMonthlyStats as $catId => $cStat) {
				foreach ($cStat as $month => $val) {
					$categoryMonthlyStats[$catId][$month] = ($val === 0.0) ? 0 : $val / $currency['exchange_rate'];
				}
			}
			foreach ($paymentModeMonthlyStats as $pmId => $pmStat) {
				foreach ($pmStat as $month => $val) {
					$paymentModeMonthlyStats[$pmId][$month] = ($val === 0.0) ? 0 : $val / $currency['exchange_rate'];
				}
			}
		}

		return [
			'stats' => $statistics,
			'memberMonthlyPaidStats' => count($memberMonthlyPaidStats) > 0 ? $memberMonthlyPaidStats : null,
			'memberMonthlySpentStats' => count($memberMonthlySpentStats) > 0 ? $memberMonthlySpentStats : null,
			'categoryStats' => $categoryStats,
			'categoryMonthlyStats' => $categoryMonthlyStats,
			'paymentModeStats' => $paymentModeStats,
			'paymentModeMonthlyStats' => $paymentModeMonthlyStats,
			'categoryMemberStats' => $categoryMemberStats,
			'memberIds' => array_keys($membersToDisplay),
			'allMemberIds' => $allMembersIds,
			'membersPaidFor' => $membersPaidFor,
			'realMonths' => $realMonths,
		];
	}

	public function getBills(
		string $projectId, ?int $lastChanged = null, ?int $offset = 0, ?int $limit = null, bool $reverse = false,
		?int $payerId = null, ?int $categoryId = null, ?int $paymentModeId = null, ?int $includeBillId = null,
		?string $searchTerm = null, ?int $deleted = 0,
	): array {
		if ($limit) {
			$bills = $this->billMapper->getBillsWithLimit(
				$projectId, null, null, null, $paymentModeId, $categoryId, null, null,
				$lastChanged, $limit, $reverse, $offset, $payerId, $includeBillId, $searchTerm, $deleted
			);
		} else {
			$bills = $this->billMapper->getBillsClassic(
				$projectId, null, null, null, $paymentModeId, $categoryId, null, null,
				$lastChanged, null, $reverse, $payerId, $deleted
			);
		}
		$billIds = $this->billMapper->getAllBillIds($projectId, $deleted);
		$ts = (new DateTime())->getTimestamp();
		return [
			'nb_bills' => $this->billMapper->countBills($projectId, $payerId, $categoryId, $paymentModeId, $deleted),
			'bills' => $bills,
			'allBillIds' => $billIds,
			'timestamp' => $ts,
		];
	}

	public function getBill(string $projectId, int $billId): array {
		$dbBillArray = $this->billMapper->getBill($projectId, $billId);
		if ($dbBillArray === null) {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND);
		}
		return $dbBillArray;
	}

	/**
	 * @param string $projectId
	 * @param string|null $date
	 * @param string|null $what
	 * @param int|null $payer
	 * @param string|null $payedFor
	 * @param float|null $amount
	 * @param string|null $repeat
	 * @param string|null $paymentMode
	 * @param int|null $paymentModeId
	 * @param int|null $categoryId
	 * @param int $repeatAllActive
	 * @param string|null $repeatUntil
	 * @param int|null $timestamp
	 * @param string|null $comment
	 * @param int|null $repeatFreq
	 * @param int $deleted
	 * @param bool $produceActivity
	 * @return int
	 * @throws CospendBasicException
	 * @throws \OCP\DB\Exception
	 */
	public function createBill(
		string $projectId, ?string $date, ?string $what, ?int $payer, ?string $payedFor,
		?float $amount, ?string $repeat, ?string $paymentMode = null, ?int $paymentModeId = null,
		?int $categoryId = null, int $repeatAllActive = 0, ?string $repeatUntil = null,
		?int $timestamp = null, ?string $comment = null, ?int $repeatFreq = null,
		int $deleted = 0, bool $produceActivity = false,
	): int {
		// if we don't have the payment modes, get them now
		if ($this->paymentModes === null) {
			$this->paymentModes = $this->getCategoriesOrPaymentModes($projectId, false);
		}

		if ($repeat === null || $repeat === '' || strlen($repeat) !== 1) {
			throw new CospendBasicException('Invalid repeat value (' . $repeat . ')', Http::STATUS_BAD_REQUEST);
		} elseif (!in_array($repeat, Application::FREQUENCIES)) {
			throw new CospendBasicException('Invalid repeat frequency value (' . $repeat . ')', Http::STATUS_BAD_REQUEST);
		}
		if ($repeatUntil !== null && $repeatUntil === '') {
			$repeatUntil = null;
		}
		// priority to timestamp (moneybuster might send both for a moment)
		if ($timestamp === null) {
			if ($date === null || $date === '') {
				throw new CospendBasicException('Timestamp (or date) field is required', Http::STATUS_BAD_REQUEST);
			} else {
				$datetime = DateTime::createFromFormat('Y-m-d', $date);
				if ($datetime === false) {
					throw new CospendBasicException('Invalid date', Http::STATUS_BAD_REQUEST);
				}
				$dateTs = $datetime->getTimestamp();
			}
		} else {
			$dateTs = $timestamp;
		}
		if ($what === null) {
			$what = '';
		}
		if ($amount === null) {
			throw new CospendBasicException('amount is required', Http::STATUS_BAD_REQUEST);
		}
		if ($payer === null) {
			throw new CospendBasicException('payer is required', Http::STATUS_BAD_REQUEST);
		}
		if ($this->getMemberById($projectId, $payer) === null) {
			throw new CospendBasicException('payer is not valid', Http::STATUS_BAD_REQUEST);
		}
		// check owers
		$owerIds = explode(',', $payedFor);
		if ($payedFor === null || $payedFor === '' || empty($owerIds)) {
			throw new CospendBasicException('payed_for is not valid (' . $payedFor . ')', Http::STATUS_BAD_REQUEST);
		}
		foreach ($owerIds as $owerId) {
			if (!is_numeric($owerId)) {
				throw new CospendBasicException('payed_for is not valid', Http::STATUS_BAD_REQUEST);
			}
			if ($this->getMemberById($projectId, (int)$owerId) === null) {
				throw new CospendBasicException('payed_for is not valid', Http::STATUS_BAD_REQUEST);
			}
		}
		// payment mode
		if (!is_null($paymentModeId)) {
			// is the old_id set for this payment mode? if yes, use it for old 'paymentmode' column
			$paymentMode = 'n';
			if (isset($this->paymentModes[$paymentModeId], $this->paymentModes[$paymentModeId]['old_id'])
				&& $this->paymentModes[$paymentModeId]['old_id'] !== null
				&& $this->paymentModes[$paymentModeId]['old_id'] !== ''
			) {
				$paymentMode = $this->paymentModes[$paymentModeId]['old_id'];
			}
		} elseif (!is_null($paymentMode)) {
			// is there a pm with this old id? if yes, use it for new id
			$paymentModeId = 0;
			foreach ($this->paymentModes as $id => $pm) {
				if ($pm['old_id'] === $paymentMode) {
					$paymentModeId = $id;
					break;
				}
			}
		}

		// last modification timestamp is now
		$ts = (new DateTime())->getTimestamp();

		$newBill = new Bill();
		$newBill->setProjectId($projectId);
		$newBill->setWhat($what);
		if ($comment !== null) {
			$newBill->setComment($comment);
		}
		$newBill->setTimestamp($dateTs);
		$newBill->setAmount($amount);
		$newBill->setPayerId($payer);
		$newBill->setRepeat($repeat);
		$newBill->setRepeatAllActive($repeatAllActive);
		$newBill->setRepeatUntil($repeatUntil);
		$newBill->setRepeatFrequency($repeatFreq ?? 1);
		$newBill->setCategoryId($categoryId ?? 0);
		$newBill->setPaymentMode($paymentMode ?? 'n');
		$newBill->setPaymentModeId($paymentModeId ?? 0);
		$newBill->setLastChanged($ts);
		$newBill->setDeleted($deleted);

		$createdBill = $this->billMapper->insert($newBill);

		$insertedBillId = $createdBill->getId();

		// insert bill owers
		foreach ($owerIds as $owerId) {
			$billOwer = new BillOwer();
			$billOwer->setBillId($insertedBillId);
			$billOwer->setMemberId((int)$owerId);
			$this->billOwerMapper->insert($billOwer);
		}

		$this->projectMapper->updateProjectLastChanged($projectId, $ts);

		if ($produceActivity) {
			$this->activityManager->triggerEvent(
				ActivityManager::COSPEND_OBJECT_BILL, $createdBill,
				ActivityManager::SUBJECT_BILL_CREATE,
				[]
			);
		}

		return $insertedBillId;
	}

	/**
	 * @param string $projectId
	 * @param int $billId
	 * @param bool $force
	 * @param bool $moveToTrash
	 * @param bool $produceActivity
	 * @return void
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteBill(
		string $projectId, int $billId, bool $force = false, bool $moveToTrash = true, bool $produceActivity = false,
	): void {
		if ($force === false) {
			$project = $this->getProjectInfo($projectId);
			if ($project['deletiondisabled']) {
				throw new CospendBasicException('', Http::STATUS_FORBIDDEN, ['error' => 'project deletion is disabled']);
			}
		}
		$billToDelete = $this->billMapper->getBillEntity($projectId, $billId);
		if ($billToDelete !== null) {
			// really delete bills that already are in the trashbin
			if ($moveToTrash && $billToDelete->getDeleted() === 0) {
				$billToDelete->setDeleted(1);
				$this->billMapper->update($billToDelete);
			} else {
				$this->billOwerMapper->deleteBillOwersOfBill($billId);
				$this->billMapper->delete($billToDelete);
			}

			$ts = (new DateTime())->getTimestamp();
			$this->projectMapper->updateProjectLastChanged($projectId, $ts);

			if ($produceActivity) {
				$this->activityManager->triggerEvent(
					ActivityManager::COSPEND_OBJECT_BILL, $billToDelete,
					ActivityManager::SUBJECT_BILL_DELETE,
					[]
				);
			}
		} else {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND, ['error' => 'not found']);
		}
	}

	/**
	 * @param string $projectId
	 * @param array $billIds
	 * @param bool $moveToTrash
	 * @return void
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteBills(string $projectId, array $billIds, bool $moveToTrash = true): void {
		foreach ($billIds as $billId) {
			if ($this->billMapper->getBill($projectId, $billId) === null) {
				throw new CospendBasicException('', Http::STATUS_NOT_FOUND);
			}
		}

		foreach ($billIds as $billId) {
			$billObj = $this->billMapper->find($billId);
			$this->deleteBill($projectId, $billId, false, $moveToTrash);
			$this->activityManager->triggerEvent(
				ActivityManager::COSPEND_OBJECT_BILL, $billObj,
				ActivityManager::SUBJECT_BILL_DELETE,
				[]
			);
		}
	}

	/**
	 * Get a member
	 *
	 * @param string $projectId
	 * @param int $memberId
	 * @return array|null
	 */
	public function getMemberById(string $projectId, int $memberId): ?array {
		$member = $this->memberMapper->getMemberById($projectId, $memberId);
		return $member?->jsonSerialize();
	}

	public function autoSettlement(string $projectId, ?int $centeredOn = null, int $precision = 2, ?int $maxTimestamp = null): void {
		$settlement = $this->getProjectSettlement($projectId, $centeredOn, $maxTimestamp);
		$transactions = $settlement['transactions'];
		if (!is_array($transactions)) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['message' => $this->l10n->t('Error when getting project settlement transactions')]);
		}

		$members = $this->getMembers($projectId);
		$memberIdToName = [];
		foreach ($members as $member) {
			$memberIdToName[$member['id']] = $member['name'];
		}

		if ($maxTimestamp) {
			$ts = $maxTimestamp - 1;
		} else {
			$ts = (new DateTime())->getTimestamp();
		}

		foreach ($transactions as $transaction) {
			$fromId = $transaction['from'];
			$toId = $transaction['to'];
			$amount = round((float)$transaction['amount'], $precision);
			$billTitle = $memberIdToName[$fromId] . ' → ' . $memberIdToName[$toId];
			try {
				$this->createBill(
					$projectId, null, $billTitle, $fromId, $toId, $amount,
					Application::FREQUENCY_NO, 'n', 0,
					Application::CATEGORY_REIMBURSEMENT, 0, null, $ts
				);
			} catch (\Throwable $e) {
				throw new CospendBasicException(
					'',
					Http::STATUS_BAD_REQUEST,
					['message' => $this->l10n->t('Error when adding a bill'), 'error' => $e->getMessage()]
				);
			}
		}
	}

	/**
	 * Get project settlement plan
	 *
	 * @param string $projectId
	 * @param int|null $centeredOn
	 * @param int|null $maxTimestamp
	 * @return array
	 */
	public function getProjectSettlement(string $projectId, ?int $centeredOn = null, ?int $maxTimestamp = null): array {
		$balances = $this->getBalance($projectId, $maxTimestamp);
		if ($centeredOn === null) {
			$transactions = $this->settle($balances);
		} else {
			$transactions = $this->centeredSettle($balances, $centeredOn);
		}
		return [
			'transactions' => $transactions,
			'balances' => $balances,
		];
	}

	/**
	 * Get a settlement plan centered on a member
	 *
	 * @param array $balances
	 * @param int $centeredOn
	 * @return array
	 */
	private function centeredSettle(array $balances, int $centeredOn): array {
		$transactions = [];
		foreach ($balances as $memberId => $balance) {
			if ($memberId !== $centeredOn) {
				if ($balance > 0.0) {
					$transactions[] = [
						'from' => $centeredOn,
						'to' => $memberId,
						'amount' => $balance
					];
				} elseif ($balance < 0.0) {
					$transactions[] = [
						'from' => $memberId,
						'to' => $centeredOn,
						'amount' => -$balance
					];
				}
			}
		}
		return $transactions;
	}

	/**
	 * Get optimal settlement of a balance list
	 *
	 * @param array $balances
	 * @return array
	 */
	private function settle(array $balances): ?array {
		$debitersCrediters = $this->orderBalance($balances);
		$debiters = $debitersCrediters[0];
		$crediters = $debitersCrediters[1];
		return $this->reduceBalance($crediters, $debiters);
	}

	/**
	 * Separate crediter and debiter balances
	 *
	 * @param array $balances
	 * @return array
	 */
	private function orderBalance(array $balances): array {
		$crediters = [];
		$debiters = [];
		foreach ($balances as $id => $balance) {
			if ($balance > 0.0) {
				$crediters[] = [$id, $balance];
			} elseif ($balance < 0.0) {
				$debiters[] = [$id, $balance];
			}
		}

		return [$debiters, $crediters];
	}

	/**
	 * Recursively produce transaction list of the settlement plan
	 *
	 * @param array $crediters
	 * @param array $debiters
	 * @param array|null $results
	 * @return array
	 */
	private function reduceBalance(array $crediters, array $debiters, ?array $results = null): ?array {
		if (count($crediters) === 0 || count($debiters) === 0) {
			return $results;
		}

		if ($results === null) {
			$results = [];
		}

		$crediters = $this->sortCreditersDebiters($crediters);
		$debiters = $this->sortCreditersDebiters($debiters, true);

		$deb = array_pop($debiters);
		$debiter = $deb[0];
		$debiterBalance = $deb[1];

		$cred = array_pop($crediters);
		$crediter = $cred[0];
		$crediterBalance = $cred[1];

		if (abs($debiterBalance) > abs($crediterBalance)) {
			$amount = abs($crediterBalance);
		} else {
			$amount = abs($debiterBalance);
		}

		$newResults = $results;
		$newResults[] = ['to' => $crediter, 'amount' => $amount, 'from' => $debiter];

		$newDebiterBalance = $debiterBalance + $amount;
		if ($newDebiterBalance < 0.0) {
			$debiters[] = [$debiter, $newDebiterBalance];
			$debiters = $this->sortCreditersDebiters($debiters, true);
		}

		$newCrediterBalance = $crediterBalance - $amount;
		if ($newCrediterBalance > 0.0) {
			$crediters[] = [$crediter, $newCrediterBalance];
			$crediters = $this->sortCreditersDebiters($crediters);
		}

		return $this->reduceBalance($crediters, $debiters, $newResults);
	}

	/**
	 * Sort crediters or debiters array by balance value
	 *
	 * @param array $arr
	 * @param bool $reverse
	 * @return array
	 */
	private function sortCreditersDebiters(array $arr, bool $reverse = false): array {
		$res = [];
		if ($reverse) {
			foreach ($arr as $elem) {
				$i = 0;
				while ($i < count($res) && $elem[1] < $res[$i][1]) {
					$i++;
				}
				array_splice($res, $i, 0, [$elem]);
			}
		} else {
			foreach ($arr as $elem) {
				$i = 0;
				while ($i < count($res) && $elem[1] >= $res[$i][1]) {
					$i++;
				}
				array_splice($res, $i, 0, [$elem]);
			}
		}
		return $res;
	}

	/**
	 * Edit a member
	 *
	 * @param string $projectId
	 * @param int $memberId
	 * @param string|null $name
	 * @param string|null $userId
	 * @param float|null $weight
	 * @param bool $activated
	 * @param string|null $color
	 * @return array|null
	 * @throws CospendBasicException
	 * @throws \OCP\DB\Exception
	 */
	public function editMember(
		string $projectId, int $memberId, ?string $name = null, ?string $userId = null,
		?float $weight = null, ?bool $activated = null, ?string $color = null,
	): ?array {
		$dbMember = $this->memberMapper->getMemberById($projectId, $memberId);
		if ($dbMember === null) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['name' => $this->l10n->t('This project have no such member')]);
		}
		$member = $dbMember->jsonSerialize();
		// delete member if it has no bill and we are disabling it
		if ($member['activated']
			&& $activated === false
			&& count($this->memberMapper->getBillIdsOfMember($memberId)) === 0
		) {
			$this->memberMapper->delete($dbMember);
			return null;
		}

		if ($name !== null) {
			if (str_contains($name, '/')) {
				throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['name' => $this->l10n->t('Invalid member name')]);
			} else {
				// get existing member with this name
				$memberWithSameName = $this->getMemberByName($projectId, $name);
				if ($memberWithSameName && $memberWithSameName['id'] !== $memberId) {
					throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['name' => $this->l10n->t('Name already exists')]);
				}
			}
		}

		if ($color !== null) {
			$color = preg_replace('/^#/', '', $color);
			if ($color === ''
				|| ((strlen($color) === 3 || strlen($color) === 6)
					&& preg_match('/^[0-9A-Fa-f]+/', $color) !== false)
			) {
				// fine
			} else {
				throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['color' => $this->l10n->t('Invalid value')]);
			}
		}

		if ($weight !== null && $weight <= 0.0) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['weight' => $this->l10n->t('Not a valid decimal value')]);
		}

		// UPDATE
		$ts = (new DateTime())->getTimestamp();
		$dbMember->setLastChanged($ts);

		if ($weight !== null) {
			$dbMember->setWeight($weight);
		}
		if ($activated !== null) {
			$dbMember->setActivated($activated ? 1 : 0);
		}

		if ($name !== null) {
			$dbMember->setName($name);
		}

		if ($color !== null) {
			$dbMember->setColor($color === '' ? null : $color);
		}

		if ($userId !== null) {
			$dbMember->setUserId($userId === '' ? null : $userId);
		}

		$this->memberMapper->update($dbMember);
		return $dbMember->jsonSerialize();
	}

	public function editProject(
		string $projectId, ?string $name = null, ?string $contact_email = null,
		?string $autoExport = null, ?string $currencyName = null, ?bool $deletionDisabled = null,
		?string $categorySort = null, ?string $paymentModeSort = null, ?int $archivedTs = null,
	): void {
		$dbProject = $this->projectMapper->find($projectId);
		if ($dbProject === null) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['message' => $this->l10n->t('There is no such project')]);
		}
		if ($name === '') {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['name' => $this->l10n->t('Name can\'t be empty')]);
		}
		if ($autoExport !== null && $autoExport !== '' && !in_array($autoExport, Application::FREQUENCIES)) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['autoexport' => $this->l10n->t('Invalid frequency')]);
		}
		if ($categorySort !== null && $categorySort !== '' && !in_array($categorySort, Application::SORT_ORDERS)) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['categorysort' => $this->l10n->t('Invalid sort order')]);
		}
		if ($paymentModeSort !== null && $paymentModeSort !== '' && !in_array($paymentModeSort, Application::SORT_ORDERS)) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['paymentmodesort' => $this->l10n->t('Invalid sort order')]);
		}

		if ($archivedTs !== null) {
			if ($archivedTs === ProjectMapper::ARCHIVED_TS_NOW) {
				$dbTs = (new DateTime())->getTimestamp();
			} elseif ($archivedTs === ProjectMapper::ARCHIVED_TS_UNSET) {
				$dbTs = null;
			} else {
				$dbTs = $archivedTs;
			}
			$dbProject->setArchivedTs($dbTs);
		}

		if ($name !== null) {
			$dbProject->setName($name);
		}

		if ($autoExport !== null && $autoExport !== '') {
			$dbProject->setAutoExport($autoExport);
		}
		if ($categorySort !== null && $categorySort !== '') {
			$dbProject->setCategorySort($categorySort);
		}
		if ($paymentModeSort !== null && $paymentModeSort !== '') {
			$dbProject->setPaymentModeSort($paymentModeSort);
		}
		if ($deletionDisabled !== null) {
			$dbProject->setDeletionDisabled($deletionDisabled ? 1 : 0);
		}
		if ($currencyName !== null) {
			$dbProject->setCurrencyName($currencyName === '' ? null : $currencyName);
		}
		$ts = (new DateTime())->getTimestamp();
		$dbProject->setLastChanged($ts);
		$this->projectMapper->update($dbProject);
	}

	/**
	 * @param string $projectId
	 * @param string $name
	 * @param float|null $weight
	 * @param bool $active
	 * @param string|null $color
	 * @param string|null $userId
	 * @return CospendMember
	 * @throws CospendBasicException
	 * @throws \OCP\DB\Exception
	 */
	public function createMember(
		string $projectId, string $name, ?float $weight = 1.0, bool $active = true,
		?string $color = null, ?string $userId = null,
	): array {
		if ($name === '') {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['error' => $this->l10n->t('Name field is required')]);
		}
		if (str_contains($name, '/')) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['error' => $this->l10n->t('Invalid member name')]);
		}
		if ($weight !== null && $weight <= 0.0) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['error' => $this->l10n->t('Weight is not a valid decimal value')]);
		}
		if ($color !== null && $color !== '' && strlen($color) !== 4 && strlen($color) !== 7) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['error' => $this->l10n->t('Invalid color value')]);
		}
		if ($this->memberMapper->getMemberByName($projectId, $name) !== null) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['error' => $this->l10n->t('This project already has this member')]);
		}
		if ($userId !== null && $this->memberMapper->getMemberByUserid($projectId, $userId) !== null) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['error' => $this->l10n->t('This project already has this member (user)')]);
		}

		$newMember = new Member();

		$weightToInsert = $weight === null ? 1.0 : $weight;
		$newMember->setWeight($weightToInsert);

		if ($color !== null
			&& (strlen($color) === 4 || strlen($color) === 7)
			&& preg_match('/^#[0-9A-Fa-f]+/', $color) !== false
		) {
			$newMember->setColor($color);
		}

		$ts = (new DateTime())->getTimestamp();
		$newMember->setLastChanged($ts);
		$newMember->setProjectId($projectId);
		if ($userId !== null) {
			$newMember->setUserId($userId);
		}
		$newMember->setActivated($active ? 1 : 0);
		$newMember->setName($name);

		$createdMember = $this->memberMapper->insert($newMember);
		return $createdMember->jsonSerialize();
	}

	/**
	 * Get members of a project
	 *
	 * @param string $projectId
	 * @param string|null $order
	 * @param int|null $lastchanged
	 * @return list<CospendMember>
	 */
	public function getMembers(string $projectId, ?string $order = null, ?int $lastchanged = null): array {
		$members = $this->memberMapper->getMembers($projectId, $order, $lastchanged);
		return array_values(array_map(static function (Member $dbMember) {
			return $dbMember->jsonSerialize();
		}, $members));
	}

	/**
	 * Get members balances for a project
	 *
	 * @param string $projectId
	 * @param int|null $maxTimestamp
	 * @return array
	 */
	private function getBalance(string $projectId, ?int $maxTimestamp = null): array {
		$membersWeight = [];
		$membersBalance = [];

		$members = $this->getMembers($projectId);
		foreach ($members as $member) {
			$memberId = $member['id'];
			$memberWeight = $member['weight'];
			$membersWeight[$memberId] = $memberWeight;
			$membersBalance[$memberId] = 0.0;
		}

		$bills = $this->billMapper->getBillsClassic($projectId, null, $maxTimestamp);
		foreach ($bills as $bill) {
			$payerId = $bill['payer_id'];
			$amount = $bill['amount'];
			$owers = $bill['owers'];

			$membersBalance[$payerId] += $amount;

			$nbOwerShares = 0.0;
			foreach ($owers as $ower) {
				$owerWeight = $ower['weight'];
				if ($owerWeight === 0.0) {
					$owerWeight = 1.0;
				}
				$nbOwerShares += $owerWeight;
			}
			foreach ($owers as $ower) {
				$owerWeight = $ower['weight'];
				if ($owerWeight === 0.0) {
					$owerWeight = 1.0;
				}
				$owerId = $ower['id'];
				$spent = $amount / $nbOwerShares * $owerWeight;
				$membersBalance[$owerId] -= $spent;
			}
		}

		return $membersBalance;
	}

	/**
	 * Check if a user is member of a given circle
	 *
	 * @param string $userId
	 * @param string $circleId
	 * @return bool
	 */
	private function isUserInCircle(string $userId, string $circleId): bool {
		try {
			$circlesManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
			$circlesManager->startSuperSession();
		} catch (Exception $e) {
			return false;
		}
		try {
			$circle = $circlesManager->getCircle($circleId);
		} catch (\OCA\Circles\Exceptions\CircleNotFoundException $e) {
			$circlesManager->stopSession();
			return false;
		}
		// is the circle owner
		$owner = $circle->getOwner();
		// the owner is also a member so this might be useless...
		if ($owner->getUserType() === 1 && $owner->getUserId() === $userId) {
			$circlesManager->stopSession();
			return true;
		} else {
			$members = $circle->getMembers();
			foreach ($members as $m) {
				// is member of this circle
				if ($m->getUserType() === 1 && $m->getUserId() === $userId) {
					$circlesManager->stopSession();
					return true;
				}
			}
		}
		$circlesManager->stopSession();
		return false;
	}

	/**
	 * For all projects the user has access to, get id => name
	 *
	 * @param string|null $userId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	public function getProjectNames(?string $userId): array {
		if (is_null($userId)) {
			return [];
		}

		$projectNames = [];

		$userProjects = $this->projectMapper->getProjects($userId);
		foreach ($userProjects as $project) {
			$projectNames[$project->getId()] = $project->getName();
		}

		$qb = $this->db->getQueryBuilder();

		// shared with user
		$qb->select('p.id', 'p.name')
			->from('cospend_projects', 'p')
			->innerJoin('p', 'cospend_shares', 's', $qb->expr()->eq('p.id', 's.project_id'))
			->where(
				$qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('s.type', $qb->createNamedParameter(Share::TYPE_USER, IQueryBuilder::PARAM_STR))
			);
		$req = $qb->executeQuery();

		while ($row = $req->fetch()) {
			// avoid putting twice the same project
			// this can happen with a share loop
			if (!isset($projectNames[$row['id']])) {
				$projectNames[$row['id']] = $row['name'];
			}
		}
		$req->closeCursor();
		$qb = $this->db->getQueryBuilder();

		// shared with one of the groups the user is member of
		$userO = $this->userManager->get($userId);

		// get group with which a project is shared
		$candidateGroupIds = [];
		$qb->select('user_id')
			->from('cospend_shares', 's')
			->where(
				$qb->expr()->eq('type', $qb->createNamedParameter(Application::SHARE_TYPE_GROUP, IQueryBuilder::PARAM_STR))
			)
			->groupBy('user_id');
		$req = $qb->executeQuery();
		while ($row = $req->fetch()) {
			$groupId = $row['user_id'];
			$candidateGroupIds[] = $groupId;
		}
		$req->closeCursor();
		$qb = $this->db->getQueryBuilder();

		// is the user member of these groups?
		foreach ($candidateGroupIds as $candidateGroupId) {
			$group = $this->groupManager->get($candidateGroupId);
			if ($group !== null && $group->inGroup($userO)) {
				// get projects shared with this group
				$qb->select('p.id', 'p.name')
					->from('cospend_projects', 'p')
					->innerJoin('p', 'cospend_shares', 's', $qb->expr()->eq('p.id', 's.project_id'))
					->where(
						$qb->expr()->eq('s.user_id', $qb->createNamedParameter($candidateGroupId, IQueryBuilder::PARAM_STR))
					)
					->andWhere(
						$qb->expr()->eq('s.type', $qb->createNamedParameter(Application::SHARE_TYPE_GROUP, IQueryBuilder::PARAM_STR))
					);
				$req = $qb->executeQuery();

				while ($row = $req->fetch()) {
					// avoid putting twice the same project
					// this can happen with a share loop
					if (!isset($projectNames[$row['id']])) {
						$projectNames[$row['id']] = $row['name'];
					}
				}
				$req->closeCursor();
				$qb = $this->db->getQueryBuilder();
			}
		}

		$circlesEnabled = $this->appManager->isEnabledForUser('circles');
		if ($circlesEnabled) {
			// get circles with which a project is shared
			$candidateCircleIds = [];
			$qb->select('user_id')
				->from('cospend_shares', 's')
				->where(
					$qb->expr()->eq('type', $qb->createNamedParameter(Application::SHARE_TYPE_CIRCLE, IQueryBuilder::PARAM_STR))
				)
				->groupBy('user_id');
			$req = $qb->executeQuery();
			while ($row = $req->fetch()) {
				$circleId = $row['user_id'];
				$candidateCircleIds[] = $circleId;
			}
			$req->closeCursor();
			$qb = $this->db->getQueryBuilder();

			// is the user member of these circles?
			foreach ($candidateCircleIds as $candidateCircleId) {
				if ($this->isUserInCircle($userId, $candidateCircleId)) {
					// get projects shared with this circle
					$qb->select('p.id', 'p.name')
						->from('cospend_projects', 'p')
						->innerJoin('p', 'cospend_shares', 's', $qb->expr()->eq('p.id', 's.project_id'))
						->where(
							$qb->expr()->eq('s.user_id', $qb->createNamedParameter($candidateCircleId, IQueryBuilder::PARAM_STR))
						)
						->andWhere(
							$qb->expr()->eq('s.type', $qb->createNamedParameter(Application::SHARE_TYPE_CIRCLE, IQueryBuilder::PARAM_STR))
						);
					$req = $qb->executeQuery();

					while ($row = $req->fetch()) {
						// avoid putting twice the same project
						// this can happen with a share loop or multiple shares
						if (!isset($projectNames[$row['id']])) {
							$projectNames[$row['id']] = $row['name'];
						}
					}
					$req->closeCursor();
					$qb = $this->db->getQueryBuilder();
				}
			}
		}
		return $projectNames;
	}

	/**
	 * Get detailed project list for a given NC user
	 *
	 * @param string $userId
	 * @return list<CospendFullProjectInfo>
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getLocalProjects(string $userId): array {
		$projectids = array_keys($this->getProjectNames($userId));

		// get the projects
		$projects = [];
		foreach ($projectids as $projectid) {
			$project = $this->getProjectInfo($projectid);
			$project['myaccesslevel'] = $this->getUserMaxAccessLevel($userId, $projectid);
			$projects[] = $project;
		}

		return $projects;
	}

	/**
	 * Get categories of a given project
	 *
	 * @param string $projectId
	 * @param bool $getCategories
	 * @return array
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getCategoriesOrPaymentModes(string $projectId, bool $getCategories = true): array {
		$jsonElementsById = [];

		if ($getCategories) {
			$billTableField = 'category_id';
			$dbTable = 'cospend_categories';
			$alias = 'cat';
		} else {
			$billTableField = 'payment_mode_id';
			$dbTable = 'cospend_paymentmodes';
			$alias = 'pm';
		}

		// get sort method
		$project = $this->projectMapper->getById($projectId);
		$sortMethod = $getCategories ? $project->getCategorySort() : $project->getPaymentModeSort();

		$elementList = $getCategories
			? $this->categoryMapper->getCategoriesOfProject($projectId)
			: $this->paymentModeMapper->getPaymentModesOfProject($projectId);

		$qb = $this->db->getQueryBuilder();

		if ($sortMethod === Application::SORT_ORDER_MANUAL || $sortMethod === Application::SORT_ORDER_ALPHA) {
			$jsonElementsById = array_reduce($elementList, function ($carry, PaymentMode|Category $element) {
				$carry[$element->getId()] = $element->jsonSerialize();
				return $carry;
			}, []);
		} elseif ($sortMethod === Application::SORT_ORDER_MOST_USED || $sortMethod === Application::SORT_ORDER_RECENTLY_USED) {
			$jsonElementsById = array_reduce($elementList, function ($carry, PaymentMode|Category $element) {
				$jsonElement = $element->jsonSerialize();
				$jsonElement['order'] = null;
				$carry[$element->getId()] = $jsonElement;
				return $carry;
			}, []);
			// now we get the order
			if ($sortMethod === Application::SORT_ORDER_MOST_USED) {
				// sort by most used
				// first get list of most used
				$mostUsedOrder = [];
				$qb->select($alias . '.id')
					->from($dbTable, $alias)
					->innerJoin($alias, 'cospend_bills', 'bill', $qb->expr()->eq($alias . '.id', 'bill.' . $billTableField))
					->where(
						$qb->expr()->eq($alias . '.project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
					)
					->andWhere(
						$qb->expr()->eq('bill.deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
					)
					->orderBy($qb->func()->count($alias . '.id'), 'DESC')
					->groupBy($alias . '.id');
				$req = $qb->executeQuery();
				$order = 0;
				while ($row = $req->fetch()) {
					$dbId = (int)$row['id'];
					$mostUsedOrder[$dbId] = $order++;
				}
				$req->closeCursor();
				// affect order
				foreach ($jsonElementsById as $elementId => $element) {
					// fallback order is more than max order
					$jsonElementsById[$elementId]['order'] = $mostUsedOrder[$elementId] ?? $order;
				}
			} else {
				// sort by most recently used
				$mostUsedOrder = [];
				$qb->select($alias . '.id')
					->from($dbTable, $alias)
					->innerJoin($alias, 'cospend_bills', 'bill', $qb->expr()->eq($alias . '.id', 'bill.' . $billTableField))
					->where(
						$qb->expr()->eq($alias . '.project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
					)
					->andWhere(
						$qb->expr()->eq('bill.deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
					)
					->orderBy($qb->func()->max('bill.timestamp'), 'DESC')
					->groupBy($alias . '.id');
				$req = $qb->executeQuery();
				$order = 0;
				while ($row = $req->fetch()) {
					$dbId = (int)$row['id'];
					$mostUsedOrder[$dbId] = $order++;
				}
				$req->closeCursor();
				// affect order
				foreach ($jsonElementsById as $elementId => $element) {
					// fallback order is more than max order
					$jsonElementsById[$elementId]['order'] = $mostUsedOrder[$elementId] ?? $order;
				}
			}
		}

		return $jsonElementsById;
	}

	/**
	 * Get currencies of a project
	 *
	 * @param string $projectId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	private function getCurrencies(string $projectId): array {
		$currencies = $this->currencyMapper->getCurrenciesOfProject($projectId);
		return array_map(function (Currency $currency) {
			$jsonCurrency = $currency->jsonSerialize();
			unset($jsonCurrency['projectid']);
			return $jsonCurrency;
		}, $currencies);
	}

	/**
	 * @param string $projectId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	private function getFederatedShares(string $projectId): array {
		$shares = $this->shareMapper->getSharesOfProject($projectId, Share::TYPE_FEDERATION);
		return array_map(function (Share $share) {
			return $share->jsonSerialize();
		}, $shares);
	}

	/**
	 * Get user shared access of a project
	 *
	 * @param string $projectId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	private function getUserShares(string $projectId): array {
		$userIdToName = [];
		$jsonShares = [];

		$shares = $this->shareMapper->getSharesOfProject($projectId, Share::TYPE_USER);
		foreach ($shares as $share) {
			if (array_key_exists($share->getUserId(), $userIdToName)) {
				$name = $userIdToName[$share->getUserId()];
			} else {
				$user = $this->userManager->get($share->getUserId());
				if ($user !== null) {
					$userIdToName[$user->getUID()] = $user->getDisplayName();
					$name = $user->getDisplayName();
				} else {
					$this->shareMapper->delete($share);
					continue;
				}
			}
			$jsonShare = $share->jsonSerialize();
			$jsonShare['name'] = $name;
			$jsonShares[] = $jsonShare;
		}

		return $jsonShares;
	}

	/**
	 * Get public links of a project
	 *
	 * @param string $projectId
	 * @param int|null $maxAccessLevel
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	public function getPublicShares(string $projectId, ?int $maxAccessLevel = null): array {
		$shares = $this->shareMapper->getSharesOfProject($projectId, Share::TYPE_PUBLIC_LINK);
		return array_map(function (Share $share) {
			return $share->jsonSerialize();
		}, $shares);
	}

	/**
	 * Get project info for a given public share token
	 *
	 * @param string $token
	 * @return array|null
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getLinkShareInfoFromShareToken(string $token): ?array {
		try {
			$share = $this->shareMapper->getLinkShareByToken($token);
			return $share->jsonSerialize();
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Get group shared access list of a project
	 *
	 * @param string $projectId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	private function getGroupShares(string $projectId): array {
		$groupIdToName = [];
		$jsonGroupShares = [];

		$groupShares = $this->shareMapper->getSharesOfProject($projectId, Share::TYPE_GROUP);
		foreach ($groupShares as $groupShare) {
			$groupId = $groupShare->getUserId();
			if (array_key_exists($groupId, $groupIdToName)) {
				$name = $groupIdToName[$groupId];
			} else {
				if ($this->groupManager->groupExists($groupId)) {
					$name = $this->groupManager->get($groupId)->getDisplayName();
					$groupIdToName[$groupId] = $name;
				} else {
					$this->shareMapper->delete($groupShare);
					continue;
				}
			}
			$jsonGroupShare = $groupShare->jsonSerialize();
			$jsonGroupShare['name'] = $name;
			$jsonGroupShare['groupid'] = $groupShare->getUserId();
			$jsonGroupShares[] = $jsonGroupShare;
		}

		return $jsonGroupShares;
	}

	/**
	 * Get circle shared access list of a project
	 *
	 * @param string $projectId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	private function getCircleShares(string $projectId): array {
		$circlesEnabled = $this->appManager->isEnabledForUser('circles');
		if (!$circlesEnabled) {
			return [];
		}

		try {
			$circlesManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
			$circlesManager->startSuperSession();
		} catch (Exception $e) {
			return [];
		}

		$jsonCircleShares = [];

		$circleShares = $this->shareMapper->getSharesOfProject($projectId, Share::TYPE_CIRCLE);
		foreach ($circleShares as $circleShare) {
			$jsonCircleShare = $circleShare->jsonSerialize();
			$circleId = $circleShare->getUserId();
			$circle = $circlesManager->getCircle($circleId);
			$jsonCircleShare['name'] = $circle->getDisplayName();
			$jsonCircleShare['circleid'] = $circleId;
			$jsonCircleShares[] = $jsonCircleShare;
		}
		$circlesManager->stopSession();
		return $jsonCircleShares;
	}

	public function deleteMember(string $projectId, int $memberId): void {
		$dbMemberToDelete = $this->memberMapper->getMemberById($projectId, $memberId);
		if ($dbMemberToDelete !== null) {
			$memberToDelete = $dbMemberToDelete->jsonSerialize();
			if (count($this->memberMapper->getBillIdsOfMember($memberId)) === 0) {
				$this->memberMapper->delete($dbMemberToDelete);
			} elseif ($memberToDelete['activated']) {
				$dbMemberToDelete->setActivated(0);
				$this->memberMapper->update($dbMemberToDelete);
			}
		} else {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND, ['error' => 'Not Found']);
		}
	}

	/**
	 * Get a member from its name
	 *
	 * @param string $projectId
	 * @param string $name
	 * @return array|null
	 */
	public function getMemberByName(string $projectId, string $name): ?array {
		$member = $this->memberMapper->getMemberByName($projectId, $name);
		return $member?->jsonSerialize();
	}

	/**
	 * Get a member from its user ID
	 *
	 * @param string $projectId
	 * @param string|null $userId
	 * @return array|null
	 */
	public function getMemberByUserid(string $projectId, ?string $userId): ?array {
		if ($userId === null) {
			return null;
		}
		$member = $this->memberMapper->getMemberByUserid($projectId, $userId);
		return $member?->jsonSerialize();
	}

	/**
	 * @param string $projectId
	 * @param int $billId
	 * @param string|null $date
	 * @param string|null $what
	 * @param int|null $payer
	 * @param string|null $payedFor
	 * @param float|null $amount
	 * @param string|null $repeat
	 * @param string|null $paymentMode
	 * @param int|null $paymentModeId
	 * @param int|null $categoryId
	 * @param int|null $repeatAllActive
	 * @param string|null $repeatUntil
	 * @param int|null $timestamp
	 * @param string|null $comment
	 * @param int|null $repeatFreq
	 * @param int|null $deleted
	 * @param bool $produceActivity
	 * @return void
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function editBill(
		string $projectId, int $billId, ?string $date, ?string $what, ?int $payer, ?string $payedFor,
		?float $amount, ?string $repeat, ?string $paymentMode = null, ?int $paymentModeId = null,
		?int $categoryId = null, ?int $repeatAllActive = null, ?string $repeatUntil = null,
		?int $timestamp = null, ?string $comment = null, ?int $repeatFreq = null,
		?int $deleted = null, bool $produceActivity = false,
	): void {
		// if we don't have the payment modes, get them now
		if ($this->paymentModes === null) {
			$this->paymentModes = $this->getCategoriesOrPaymentModes($projectId, false);
		}

		$dbBill = $this->billMapper->getBillEntity($projectId, $billId);
		// first check the bill exists
		if ($dbBill === null) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['message' => $this->l10n->t('There is no such bill')]);
		}

		// validate params

		if ($repeat !== null && $repeat !== '') {
			if (!in_array($repeat, Application::FREQUENCIES)) {
				throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['repeat' => $this->l10n->t('Invalid value')]);
			}
		}

		if ($timestamp === null && $date !== null && $date !== '') {
			$datetime = DateTime::createFromFormat('Y-m-d', $date);
			if ($datetime === false) {
				throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['date' => $this->l10n->t('Invalid value')]);
			}
		}

		if ($payer !== null) {
			$dbPayer = $this->memberMapper->getMemberById($projectId, $payer);
			if ($dbPayer === null) {
				throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['payer' => $this->l10n->t('Not a valid choice')]);
			}
		}

		// validate owers
		$owerIds = null;
		// check owers
		if ($payedFor !== null && $payedFor !== '') {
			$owerIds = explode(',', $payedFor);
			if (empty($owerIds)) {
				throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['payed_for' => $this->l10n->t('Invalid value')]);
			} else {
				foreach ($owerIds as $owerId) {
					if (!is_numeric($owerId)) {
						throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['payed_for' => $this->l10n->t('Invalid value')]);
					}
					if ($this->getMemberById($projectId, (int)$owerId) === null) {
						throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['payed_for' => $this->l10n->t('Not a valid choice')]);
					}
				}
			}
		}

		// UPDATE
		// set last modification timestamp
		$ts = (new DateTime())->getTimestamp();
		$dbBill->setLastChanged($ts);
		if ($what !== null) {
			$dbBill->setWhat($what);
		}
		if ($comment !== null) {
			$dbBill->setComment($comment);
		}
		if ($deleted !== null) {
			$dbBill->setDeleted($deleted);
		}
		if ($repeat !== null && $repeat !== '') {
			if (in_array($repeat, Application::FREQUENCIES)) {
				$dbBill->setRepeat($repeat);
			}
		}
		if ($repeatFreq !== null) {
			$dbBill->setRepeatFrequency($repeatFreq);
		}
		if ($repeatUntil !== null) {
			$dbBill->setRepeatUntil($repeatUntil === '' ? null : $repeatUntil);
		}
		if ($repeatAllActive !== null) {
			$dbBill->setRepeatAllActive($repeatAllActive);
		}
		// payment mode
		if ($paymentModeId !== null) {
			// is the old_id set for this payment mode? if yes, use it for old 'paymentmode' column
			$paymentMode = 'n';
			if (isset($this->paymentModes[$paymentModeId]['old_id'])
				&& $this->paymentModes[$paymentModeId]['old_id'] !== null
				&& $this->paymentModes[$paymentModeId]['old_id'] !== ''
			) {
				$paymentMode = $this->paymentModes[$paymentModeId]['old_id'];
			}
			$dbBill->setPaymentModeId($paymentModeId);
			$dbBill->setPaymentMode($paymentMode);
		} elseif ($paymentMode !== null) {
			// is there a pm with this old id? if yes, use it for new id
			$paymentModeId = 0;
			foreach ($this->paymentModes as $id => $pm) {
				if ($pm['old_id'] === $paymentMode) {
					$paymentModeId = $id;
					break;
				}
			}
			$dbBill->setPaymentModeId($paymentModeId);
			$dbBill->setPaymentMode($paymentMode);
		}
		if ($categoryId !== null) {
			$dbBill->setCategoryId($categoryId);
		}
		// priority to timestamp (moneybuster might send both for a moment)
		if ($timestamp !== null) {
			$dbBill->setTimestamp($timestamp);
		} elseif ($date !== null && $date !== '') {
			$datetime = DateTime::createFromFormat('Y-m-d', $date);
			if ($datetime !== false) {
				$dateTs = $datetime->getTimestamp();
				$dbBill->setTimestamp($dateTs);
			}
		}
		if ($amount !== null) {
			$dbBill->setAmount($amount);
		}
		if ($payer !== null) {
			$dbBill->setPayerId($payer);
		}

		$this->billMapper->update($dbBill);

		// edit the bill owers
		if ($owerIds !== null) {
			// delete old bill owers
			$this->billOwerMapper->deleteBillOwersOfBill($billId);
			// insert bill owers
			foreach ($owerIds as $owerId) {
				$billOwer = new BillOwer();
				$billOwer->setBillId($billId);
				$billOwer->setMemberId((int)$owerId);
				$this->billOwerMapper->insert($billOwer);
			}
		}

		$this->projectMapper->updateProjectLastChanged($projectId, $ts);

		if ($produceActivity) {
			$this->activityManager->triggerEvent(
				ActivityManager::COSPEND_OBJECT_BILL, $dbBill,
				ActivityManager::SUBJECT_BILL_UPDATE,
				[]
			);
		}
	}

	/**
	 * @param string $projectId
	 * @param array $billIds
	 * @param string|null $date
	 * @param string|null $what
	 * @param int|null $payer
	 * @param string|null $payedFor
	 * @param float|null $amount
	 * @param string|null $repeat
	 * @param string|null $paymentMode
	 * @param int|null $paymentModeId
	 * @param int|null $categoryId
	 * @param int|null $repeatAllActive
	 * @param string|null $repeatUntil
	 * @param int|null $timestamp
	 * @param string|null $comment
	 * @param int|null $repeatFreq
	 * @param int|null $deleted
	 * @param bool $produceActivity
	 * @return void
	 * @throws CospendBasicException
	 * @throws \OCP\DB\Exception
	 */
	public function editBills(
		string $projectId, array $billIds, ?string $date = null, ?string $what = null,
		?int $payer = null, ?string $payedFor = null,
		?float $amount = null, ?string $repeat = null,
		?string $paymentMode = null, ?int $paymentModeId = null,
		?int $categoryId = null,
		?int $repeatAllActive = null, ?string $repeatUntil = null, ?int $timestamp = null,
		?string $comment = null, ?int $repeatFreq = null, ?int $deleted = null, bool $produceActivity = false,
	): void {
		foreach ($billIds as $billId) {
			$this->editBill(
				$projectId, $billId, $date, $what, $payer, $payedFor,
				$amount, $repeat, $paymentMode, $paymentModeId, $categoryId,
				$repeatAllActive, $repeatUntil, $timestamp, $comment,
				$repeatFreq, $deleted, $produceActivity
			);
		}
	}

	/**
	 * @param string $projectId
	 * @return void
	 */
	public function clearTrashBin(string $projectId): void {
		$this->billMapper->deleteDeletedBills($projectId);
	}

	/**
	 * @param string $projectId
	 * @param int $billId
	 * @return array
	 * @throws CospendBasicException
	 */
	public function repeatBill(string $projectId, int $billId): array {
		$bill = $this->billMapper->getBill($projectId, $billId);
		if ($bill === null) {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND);
		}
		return $this->cronRepeatBills($billId);
	}

	/**
	 * daily check of repeated bills
	 *
	 * @param int|null $billId
	 * @return list<array{new_bill_id: int, date_orig: string, date_repeat: string, what: string, project_name: string}>
	 */
	public function cronRepeatBills(?int $billId = null): array {
		$result = [];
		$projects = [];
		$now = new DateTimeImmutable();
		// in case cron job wasn't executed during several days,
		// continue trying to repeat bills as long as there was at least one repeated
		$continue = true;
		while ($continue) {
			$continue = false;

			/** @var DateTimeZone[] $timezoneByProjectId */
			$timezoneByProjectId = [];
			// get bills with repetition flag
			$bills = $this->billMapper->getBillsToRepeat($billId);
			foreach ($bills as $bill) {
				if (!isset($timezoneByProjectId[$bill->getProjectId()])) {
					$timezoneByProjectId[$bill->getProjectId()] = $this->getProjectTimeZone($bill->getProjectId());
				}
			}
			$jsonBills = array_map(function (Bill $bill) {
				return $bill->jsonSerialize();
			}, $bills);

			foreach ($jsonBills as $jsonBill) {
				$billProjectId = $jsonBill['projectid'];
				$billDate = (new DateTimeImmutable())->setTimestamp($jsonBill['timestamp'])->setTimezone($timezoneByProjectId[$billProjectId]);
				$nextDate = $this->getNextRepetitionDate($jsonBill, $billDate);

				// Unknown repeat interval
				if ($nextDate === null) {
					continue;
				}

				// Repeat if $nextDate is in the past (or today)
				$nowTs = $now->getTimestamp();
				$nextDateTs = $nextDate->getTimestamp();
				if ($nowTs > $nextDateTs || $nextDate->format('Y-m-d') === $now->format('Y-m-d')) {
					$newBillId = $this->repeatLocalBill($jsonBill['projectid'], $jsonBill['id'], $nextDate);
					// bill was not repeated (because of disabled owers or repeatuntil)
					if ($newBillId === null) {
						continue;
					}
					if (!array_key_exists($jsonBill['projectid'], $projects)) {
						$projects[$jsonBill['projectid']] = $this->getProjectInfo($jsonBill['projectid']);
					}
					$result[] = [
						'new_bill_id' => $newBillId,
						'date_orig' => $billDate->format('Y-m-d'),
						'date_repeat' => $nextDate->format('Y-m-d'),
						'what' => $jsonBill['what'],
						'project_name' => $projects[$jsonBill['projectid']]['name'],
					];
					$continue = true;
					// when only repeating one bill, this newly created bill is the one we want to potentially repeat
					$billId = $newBillId;
				}
			}
		}
		return $result;
	}

	private function getProjectTimeZone(string $projectId): DateTimeZone {
		$projectInfo = $this->getProjectInfo($projectId);
		$userId = $projectInfo['userid'];
		$timeZone = $this->config->getUserValue($userId, 'core', 'timezone', null);
		$serverTimeZone = date_default_timezone_get() ?: 'UTC';

		if ($timeZone === null) {
			$timeZone = $serverTimeZone;
		}

		try {
			return new DateTimeZone($timeZone);
		} catch (Exception $e) {
			return new DateTimeZone($serverTimeZone);
		}
	}

	private function copyBillPaymentModeOver(string $projectId, array $bill, string $toProjectId): int {
		$originPayments = $this->getCategoriesOrPaymentModes($projectId, false);
		$destinationPayments = $this->getCategoriesOrPaymentModes($toProjectId, false);

		if ($bill['paymentmodeid'] !== 0) {
			$originPayment = array_filter($originPayments, static function ($val) use ($bill) {
				return $val['id'] === $bill['paymentmodeid'];
			});
			$originPayment = array_shift($originPayment);

			// find a payment mode with the same name
			$paymentNameMatches = array_filter($destinationPayments, static function ($val) use ($originPayment) {
				return $val['name'] === $originPayment['name'];
			});

			// no payment mode match, means new mode
			if (count($paymentNameMatches) === 0) {
				return $this->createPaymentMode($toProjectId, $originPayment['name'], $originPayment['icon'], $originPayment['color']);
			} else {
				return array_shift($paymentNameMatches)['id'];
			}
		}

		return $bill['paymentmodeid'];
	}

	private function copyBillCategoryOver(string $projectId, array $bill, string $toProjectId): int {
		$originCategories = $this->getCategoriesOrPaymentModes($projectId);
		$destinationCategories = $this->getCategoriesOrPaymentModes($toProjectId);

		if ($bill['categoryid'] !== 0 && $bill['categoryid'] !== Application::CATEGORY_REIMBURSEMENT) {
			$originCategory = array_filter($originCategories, static function ($val) use ($bill) {
				return $val['id'] === $bill['categoryid'];
			});
			$originCategory = array_shift($originCategory);

			// find a category with the same name
			$categoryNameMatches = array_filter($destinationCategories, static function ($val) use ($originCategory) {
				return $val['name'] === $originCategory['name'];
			});

			// no category match, means new category
			if (count($categoryNameMatches) === 0) {
				return $this->createCategory($toProjectId, $originCategory['name'], $originCategory['icon'], $originCategory['color']);
			} else {
				return array_shift($categoryNameMatches)['id'];
			}
		}

		return $bill['categoryid'];
	}

	/**
	 * @param string $projectId
	 * @param int $billId
	 * @param string $toProjectId
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	public function moveBill(string $projectId, int $billId, string $toProjectId): array {
		$bill = $this->billMapper->getBill($projectId, $billId);

		// get all members in all the projects and try to match them
		$originMembers = $this->getMembers($projectId, 'lowername');
		$destinationMembers = $this->getMembers($toProjectId, 'lowername');

		// try to match them
		$originalPayer = $originMembers;
		$originalPayer = array_filter($originalPayer, static function ($val) use ($bill) {
			return $val['id'] === $bill['payer_id'];
		});
		$originalPayer = array_shift($originalPayer);

		$newPayer = $destinationMembers;
		$newPayer = array_filter($newPayer, static function ($val) use ($originalPayer) {
			return $val['name'] === $originalPayer['name'];
		});

		if (count($newPayer) < 1) {
			return ['message' => $this->l10n->t('Cannot match payer')];
		}

		$newPayer = array_shift($newPayer);

		// match owers too, these do not mind that much, the user will be able to modify the new invoice just after moving it
		$newOwers = array_filter($destinationMembers, static function ($member) use ($bill) {
			$matches = array_filter($bill['owers'], static function ($oldMember) use ($member) {
				return $oldMember['name'] === $member['name'];
			});

			if (count($matches) === 0) {
				return false;
			}

			return true;
		});

		$newCategoryId = $this->copyBillCategoryOver($projectId, $bill, $toProjectId);
		$newPaymentId = $this->copyBillPaymentModeOver($projectId, $bill, $toProjectId);

		try {
			$insertedId = $this->createBill(
				$toProjectId, null, $bill['what'], $newPayer['id'],
				implode(',', array_column($newOwers, 'id')), $bill['amount'], $bill['repeat'],
				$bill['paymentmode'], $newPaymentId,
				$newCategoryId, $bill['repeatallactive'], $bill['repeatuntil'],
				$bill['timestamp'], $bill['comment'], $bill['repeatfreq'], $bill['deleted']
			);
		} catch (\Throwable $e) {
			return ['message' => $this->l10n->t('Cannot create new bill: %1$s', $e->getMessage())];
		}

		// remove the old bill
		$this->deleteBill($projectId, $billId, true);

		return ['inserted_id' => $insertedId];
	}

	/**
	 * duplicate the bill today and give it the repeat flag
	 * remove the repeat flag on original bill
	 *
	 * @param string $projectId
	 * @param int $billId
	 * @param DateTimeImmutable $targetDatetime
	 * @return int|null
	 * @throws \OCP\DB\Exception
	 */
	private function repeatLocalBill(string $projectId, int $billId, DateTimeImmutable $targetDatetime): ?int {
		$bill = $this->billMapper->getBill($projectId, $billId);

		$owerIds = [];
		if (((int)$bill['repeatallactive']) === 1) {
			$pInfo = $this->getProjectInfo($projectId);
			foreach ($pInfo['active_members'] as $am) {
				$owerIds[] = $am['id'];
			}
		} else {
			foreach ($bill['owers'] as $ower) {
				if ($ower['activated']) {
					$owerIds[] = $ower['id'];
				}
			}
		}
		$owerIdsStr = implode(',', $owerIds);
		// if all owers are disabled, don't try to repeat the bill and remove repeat flag
		if (count($owerIds) === 0) {
			$this->editBill(
				$projectId, $billId, null, null, null, null,
				null, Application::FREQUENCY_NO, null, null,
				null, null
			);
			return null;
		}

		// if bill should be repeated only until...
		if ($bill['repeatuntil'] !== null && $bill['repeatuntil'] !== '') {
			$untilDate = DateTimeImmutable::createFromFormat('Y-m-d', $bill['repeatuntil']);
			if ($targetDatetime > $untilDate) {
				$this->editBill(
					$projectId, $billId, null, null, null, null,
					null, Application::FREQUENCY_NO, null, null,
					null, null
				);
				return null;
			}
		}

		try {
			$newBillId = $this->createBill(
				$projectId, null, $bill['what'], $bill['payer_id'],
				$owerIdsStr, $bill['amount'], $bill['repeat'],
				$bill['paymentmode'], $bill['paymentmodeid'],
				$bill['categoryid'], $bill['repeatallactive'], $bill['repeatuntil'],
				$targetDatetime->getTimestamp(), $bill['comment'], $bill['repeatfreq']
			);
		} catch (\Throwable $e) {
			$newBillId = 0;
		}

		$billObj = $this->billMapper->find($newBillId);
		$this->activityManager->triggerEvent(
			ActivityManager::COSPEND_OBJECT_BILL, $billObj,
			ActivityManager::SUBJECT_BILL_CREATE,
			[]
		);

		// now we can remove the repeat flag on the original bill
		$this->editBill($projectId, $billId, null, $bill['what'], $bill['payer_id'], null,
			$bill['amount'], Application::FREQUENCY_NO, null, null, null, null);
		return $newBillId;
	}

	/**
	 * Get next repetition date of a bill
	 *
	 * @param array $bill
	 * @param DateTimeImmutable $billDate
	 * @return DateTimeImmutable|null
	 * @throws Exception
	 */
	private function getNextRepetitionDate(array $bill, DateTimeImmutable $billDate): ?DateTimeImmutable {
		switch ($bill['repeat']) {
			case Application::FREQUENCY_DAILY:
				if ($bill['repeatfreq'] < 2) {
					return $billDate->add(new DateInterval('P1D'));
				} else {
					return $billDate->add(new DateInterval('P' . $bill['repeatfreq'] . 'D'));
				}
				break;

			case Application::FREQUENCY_WEEKLY:
				if ($bill['repeatfreq'] < 2) {
					return $billDate->add(new DateInterval('P7D'));
				} else {
					$nbDays = 7 * $bill['repeatfreq'];
					return $billDate->add(new DateInterval('P' . $nbDays . 'D'));
				}
				break;

			case Application::FREQUENCY_BI_WEEKLY:
				return $billDate->add(new DateInterval('P14D'));
				break;

			case Application::FREQUENCY_SEMI_MONTHLY:
				$day = (int)$billDate->format('d');
				$month = (int)$billDate->format('m');
				$year = (int)$billDate->format('Y');

				// first of next month
				if ($day >= 15) {
					if ($month === 12) {
						$nextYear = $year + 1;
						$nextMonth = 1;
						return $billDate->setDate($nextYear, $nextMonth, 1);
					} else {
						$nextMonth = $month + 1;
						return $billDate->setDate($year, $nextMonth, 1);
					}
				} else {
					// 15 of same month
					return $billDate->setDate($year, $month, 15);
				}
				break;

			case Application::FREQUENCY_MONTHLY:
				$freq = ($bill['repeatfreq'] < 2) ? 1 : $bill['repeatfreq'];
				$billMonth = (int)$billDate->format('m');
				$yearDelta = intdiv($billMonth + $freq - 1, 12);
				$nextYear = ((int)$billDate->format('Y')) + $yearDelta;
				$nextMonth = (($billMonth + $freq - 1) % 12) + 1;

				// same day of month if possible, otherwise at end of month
				$firstOfNextMonth = $billDate->setDate($nextYear, $nextMonth, 1);
				$billDay = (int)$billDate->format('d');
				$nbDaysInTargetMonth = (int)$firstOfNextMonth->format('t');
				if ($billDay > $nbDaysInTargetMonth) {
					return $billDate->setDate($nextYear, $nextMonth, $nbDaysInTargetMonth);
				} else {
					return $billDate->setDate($nextYear, $nextMonth, $billDay);
				}
				break;

			case Application::FREQUENCY_YEARLY:
				$freq = ($bill['repeatfreq'] < 2) ? 1 : $bill['repeatfreq'];
				$billYear = (int)$billDate->format('Y');
				$billMonth = (int)$billDate->format('m');
				$billDay = (int)$billDate->format('d');
				$nextYear = $billYear + $freq;

				// same day of month if possible, otherwise at end of month + same month
				$firstDayOfTargetMonth = $billDate->setDate($nextYear, $billMonth, 1);
				$nbDaysInTargetMonth = (int)$firstDayOfTargetMonth->format('t');
				if ($billDay > $nbDaysInTargetMonth) {
					return $billDate->setDate($nextYear, $billMonth, $nbDaysInTargetMonth);
				} else {
					return $billDate->setDate($nextYear, $billMonth, $billDay);
				}
				break;
		}

		return null;
	}

	/**
	 * @param string $projectId
	 * @param string $name
	 * @param string|null $icon
	 * @param string $color
	 * @param int|null $order
	 * @return int
	 * @throws \OCP\DB\Exception
	 */
	public function createPaymentMode(string $projectId, string $name, ?string $icon, string $color, ?int $order = 0): int {
		$pm = new PaymentMode();
		$pm->setProjectId($projectId);
		$pm->setName($name);
		$pm->setOrder(is_null($order) ? 0 : $order);
		$pm->setColor($color);
		$pm->setEncodedIcon(($icon !== null && $icon !== '') ? urlencode($icon) : $icon);
		$insertedPm = $this->paymentModeMapper->insert($pm);
		return $insertedPm->getId();
	}

	/**
	 * @param string $projectId
	 * @param int $pmId
	 * @return array|null
	 * @throws CospendBasicException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getPaymentMode(string $projectId, int $pmId): ?array {
		try {
			$pm = $this->paymentModeMapper->getPaymentModeOfProject($projectId, $pmId);
		} catch (DoesNotExistException $e) {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND, ['message' => 'payment mode not found']);
		}
		return $pm->jsonSerialize();
	}

	/**
	 * @param string $projectId
	 * @param int $pmId
	 * @return void
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deletePaymentMode(string $projectId, int $pmId): void {
		$pmToDelete = $this->paymentModeMapper->getPaymentModeOfProject($projectId, $pmId);
		$this->paymentModeMapper->delete($pmToDelete);

		// then get rid of this pm in bills
		$this->billMapper->removePaymentModeInProject($projectId, $pmId);
	}

	/**
	 * @param string $projectId
	 * @param array $order
	 * @return void
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function savePaymentModeOrder(string $projectId, array $order): void {
		foreach ($order as $o) {
			$paymentMode = $this->paymentModeMapper->getPaymentModeOfProject($projectId, $o['id']);
			$paymentMode->setOrder($o['order']);
			$this->paymentModeMapper->update($paymentMode);
		}
	}

	/**
	 * @param string $projectId
	 * @param int $pmId
	 * @param string|null $name
	 * @param string|null $icon
	 * @param string|null $color
	 * @return array
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function editPaymentMode(
		string $projectId, int $pmId, ?string $name = null, ?string $icon = null, ?string $color = null,
	): array {
		if ($name === null || $name === '') {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['message' => $this->l10n->t('Incorrect field values')]);
		}
		$paymentMode = $this->paymentModeMapper->getPaymentModeOfProject($projectId, $pmId);
		$paymentMode->setName($name);
		$paymentMode->setColor($color);
		$paymentMode->setEncodedIcon(($icon !== null && $icon !== '') ? urlencode($icon) : $icon);
		$editedPm = $this->paymentModeMapper->update($paymentMode);
		return $editedPm->jsonSerialize();
	}

	/**
	 * Add a new category
	 *
	 * @param string $projectId
	 * @param string $name
	 * @param string|null $icon
	 * @param string $color
	 * @param int|null $order
	 * @return int
	 * @throws \OCP\DB\Exception
	 */
	public function createCategory(string $projectId, string $name, ?string $icon, string $color, ?int $order = 0): int {
		$category = new Category();
		$category->setProjectId($projectId);
		$category->setName($name);
		$category->setOrder(is_null($order) ? 0 : $order);
		$category->setColor($color);
		$category->setEncodedIcon(($icon !== null && $icon !== '') ? urlencode($icon) : $icon);
		$insertedCategory = $this->categoryMapper->insert($category);
		return $insertedCategory->getId();
	}

	/**
	 * Get a category
	 *
	 * @param string $projectId
	 * @param int $categoryId
	 * @return array
	 * @throws CospendBasicException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getCategory(string $projectId, int $categoryId): array {
		try {
			$category = $this->categoryMapper->getCategoryOfProject($projectId, $categoryId);
		} catch (DoesNotExistException $e) {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND, ['message' => 'category not found']);
		}
		return $category->jsonSerialize();
	}

	/**
	 * Delete a category
	 *
	 * @param string $projectId
	 * @param int $categoryId
	 * @return void
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteCategory(string $projectId, int $categoryId): void {
		$categoryToDelete = $this->categoryMapper->getCategoryOfProject($projectId, $categoryId);
		$this->categoryMapper->delete($categoryToDelete);

		// then get rid of this category in bills
		$this->billMapper->removeCategoryInProject($projectId, $categoryId);
	}

	/**
	 * Save the manual category order
	 *
	 * @param string $projectId
	 * @param array $order
	 * @return void
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function saveCategoryOrder(string $projectId, array $order): void {
		foreach ($order as $o) {
			$category = $this->categoryMapper->getCategoryOfProject($projectId, $o['id']);
			$category->setOrder($o['order']);
			$this->categoryMapper->update($category);
		}
	}

	/**
	 * Edit a category
	 *
	 * @param string $projectId
	 * @param int $categoryId
	 * @param string|null $name
	 * @param string|null $icon
	 * @param string|null $color
	 * @return array
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function editCategory(
		string $projectId, int $categoryId, ?string $name = null, ?string $icon = null, ?string $color = null,
	): array {
		if ($name === null || $name === '') {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['message' => $this->l10n->t('Incorrect field values')]);
		}
		$category = $this->categoryMapper->getCategoryOfProject($projectId, $categoryId);
		$category->setName($name);
		$category->setColor($color);
		$category->setEncodedIcon(($icon !== null && $icon !== '') ? urlencode($icon) : $icon);
		$editedCategory = $this->categoryMapper->update($category);
		return $editedCategory->jsonSerialize();
	}

	/**
	 * Add a currency
	 *
	 * @param string $projectId
	 * @param string $name
	 * @param float $rate
	 * @return int
	 * @throws \OCP\DB\Exception
	 */
	public function createCurrency(string $projectId, string $name, float $rate): int {
		$currency = new Currency();
		$currency->setName($name);
		$currency->setExchangeRate($rate);
		$currency->setProjectId($projectId);
		$insertedCurrency = $this->currencyMapper->insert($currency);
		return $insertedCurrency->getId();
	}

	/**
	 * Delete one currency
	 *
	 * @param string $projectId
	 * @param int $currencyId
	 * @return void
	 * @throws CospendBasicException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteCurrency(string $projectId, int $currencyId): void {
		try {
			$currency = $this->currencyMapper->getCurrencyOfProject($projectId, $currencyId);
		} catch (DoesNotExistException $e) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['message' => $this->l10n->t('Not found')]);
		}
		$this->currencyMapper->delete($currency);
	}

	/**
	 * Edit a currency
	 *
	 * @param string $projectId
	 * @param int $currencyId
	 * @param string $name
	 * @param float $rate
	 * @return array
	 * @throws CospendBasicException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function editCurrency(string $projectId, int $currencyId, string $name, float $rate): array {
		if ($name === '' || $rate === 0.0) {
			throw new CospendBasicException('', Http::STATUS_BAD_REQUEST, ['message' => $this->l10n->t('Incorrect field values')]);
		}
		try {
			$currency = $this->currencyMapper->getCurrencyOfProject($projectId, $currencyId);
		} catch (DoesNotExistException $e) {
			throw new CospendBasicException('', Http::STATUS_NOT_FOUND, ['message' => $this->l10n->t('This project have no such currency')]);
		}
		$currency->setExchangeRate($rate);
		$currency->setName($name);
		$editedCurrency = $this->currencyMapper->update($currency);
		return $editedCurrency->jsonSerialize();
	}

	/**
	 * Generate a random share token for a project
	 *
	 * @return string
	 */
	private function generateShareToken() {
		return $this->secureRandom->generate(
			FederationManager::TOKEN_LENGTH,
			ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
		);
	}

	/**
	 * Add a federated shared access to a project
	 *
	 * @param string $projectId
	 * @param string $userCloudId
	 * @param string $fromUserId
	 * @param int $accessLevel
	 * @param bool $manually_added
	 * @return Share
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function createFederatedShare(
		string $projectId, string $userCloudId, string $fromUserId, int $accessLevel = Application::ACCESS_LEVEL_PARTICIPANT,
		bool $manually_added = true,
	): Share {
		try {
			$this->shareMapper->getFederatedShareByProjectIdAndUserCloudId($projectId, $userCloudId);
			throw new CospendBasicException('Share already exists', Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException $e) {
		}

		$userMaxAccessLevel = $this->getUserMaxAccessLevel($fromUserId, $projectId);
		if ($userMaxAccessLevel < $accessLevel) {
			throw new CospendBasicException(
				'This user is not authorized to create a federated share with such access level. Max (' . $userMaxAccessLevel . ')',
				Http::STATUS_BAD_REQUEST,
			);
		}

		$shareToken = $this->generateShareToken();
		$newShare = new Share();
		$newShare->setProjectId($projectId);
		$newShare->setUserId($shareToken);
		$newShare->setType(Share::TYPE_FEDERATION);
		$newShare->setAccessLevel($accessLevel);
		$newShare->setUserCloudId($userCloudId);
		$newShare->setState(Invitation::STATE_PENDING);
		$insertedShare = $this->shareMapper->insert($newShare);

		$sharedBy = $this->userManager->get($fromUserId);
		$project = $this->projectMapper->getById($projectId);
		$response = $this->backendNotifier->sendRemoteShare($projectId, $shareToken, $userCloudId, $sharedBy, 'user', $project);
		if (!$response) {
			$this->shareMapper->delete($insertedShare);
			throw new CospendBasicException('Cannot reach remote server', Http::STATUS_BAD_REQUEST);
		}

		return $insertedShare;
	}

	/**
	 * Delete federated shared access
	 *
	 * @param string $projectId
	 * @param int $shId
	 * @return void
	 * @throws CospendBasicException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteFederatedShare(string $projectId, int $shId): void {
		try {
			$share = $this->shareMapper->getShareById($shId);
		} catch (DoesNotExistException $e) {
			throw new CospendBasicException('Share does not exist', Http::STATUS_BAD_REQUEST);
		}

		if ($share->getProjectId() !== $projectId) {
			throw new CospendBasicException('Wrong projectId in the share to delete', Http::STATUS_BAD_REQUEST);
		}

		$cloudId = $this->cloudIdManager->resolveCloudId($share->getUserCloudId());

		$this->backendNotifier->sendRemoteUnShare(
			$cloudId->getRemote(),
			$projectId,
			$share->getUserId(),
		);

		$this->shareMapper->delete($share);
	}

	/**
	 * Add a user shared access to a project
	 *
	 * @param string $projectId
	 * @param string $userId
	 * @param string $fromUserId
	 * @param int $accesslevel
	 * @param bool $manually_added
	 * @return array
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function createUserShare(
		string $projectId, string $userId, string $fromUserId, int $accesslevel = Application::ACCESS_LEVEL_PARTICIPANT,
		bool $manually_added = true,
	): array {
		$user = $this->userManager->get($userId);
		if ($user === null || $userId === $fromUserId) {
			return ['message' => $this->l10n->t('No such user')];
		}

		$userName = $user->getDisplayName();
		$projectInfo = $this->getProjectInfo($projectId);
		// check if someone tries to share the project with its owner
		if ($userId === $projectInfo['userid']) {
			return ['message' => $this->l10n->t('Impossible to share the project with its owner')];
		}

		try {
			$this->shareMapper->getShareByProjectAndUser($projectId, $userId, Share::TYPE_USER);
			return ['message' => $this->l10n->t('Already shared with this user')];
		} catch (DoesNotExistException $e) {
		}

		if ($this->getUserMaxAccessLevel($fromUserId, $projectId) < $accesslevel) {
			return ['message' => $this->l10n->t('You are not authorized to give such access level')];
		}

		$share = new Share();
		$share->setProjectId($projectId);
		$share->setUserId($userId);
		$share->setType(Share::TYPE_USER);
		$share->setAccessLevel($accesslevel);
		$share->setManuallyAdded($manually_added ? 1 : 0);
		$insertedShare = $this->shareMapper->insert($share);

		// activity
		$projectObj = $this->projectMapper->find($projectId);
		$this->activityManager->triggerEvent(
			ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
			ActivityManager::SUBJECT_PROJECT_SHARE,
			['who' => $userId, 'type' => Application::SHARE_TYPE_USER]
		);

		// SEND NOTIFICATION
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$acceptAction = $notification->createAction();
		$acceptAction->setLabel('accept')
			->setLink('/apps/cospend', 'GET');

		$declineAction = $notification->createAction();
		$declineAction->setLabel('decline')
			->setLink('/apps/cospend', 'GET');

		$notification->setApp('cospend')
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('addusershare', $projectId)
			->setSubject('add_user_share', [$fromUserId, $projectInfo['name']])
			->addAction($acceptAction)
			->addAction($declineAction);

		$manager->notify($notification);

		$jsonShare = $insertedShare->jsonSerialize();
		$jsonShare['name'] = $userName;
		return $jsonShare;
	}

	/**
	 * Add public share access (public link with token)
	 *
	 * @param string $projectId
	 * @param string|null $label
	 * @param string|null $password
	 * @param int $accesslevel
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	public function createPublicShare(
		string $projectId, ?string $label = null, ?string $password = null, int $accesslevel = Application::ACCESS_LEVEL_PARTICIPANT,
	): array {
		$shareToken = $this->generateShareToken();
		$share = new Share();
		$share->setProjectId($projectId);
		$share->setUserId($shareToken);
		$share->setType(Share::TYPE_PUBLIC_LINK);
		$share->setAccessLevel($accesslevel);
		$share->setLabel($label);
		$share->setPassword($password);
		$insertedShare = $this->shareMapper->insert($share);

		//// activity
		//$projectObj = $this->projectMapper->find($projectid);
		//$this->activityManager->triggerEvent(
		//    ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
		//    ActivityManager::SUBJECT_PROJECT_SHARE,
		//    ['who' => $userid, 'type' => 'u']
		//);

		//// SEND NOTIFICATION
		//$projectInfo = $this->getProjectInfo($projectid);
		//$manager = $this->notificationManager;
		//$notification = $manager->createNotification();

		//$acceptAction = $notification->createAction();
		//$acceptAction->setLabel('accept')
		//    ->setLink('/apps/cospend', 'GET');

		//$declineAction = $notification->createAction();
		//$declineAction->setLabel('decline')
		//    ->setLink('/apps/cospend', 'GET');

		//$notification->setApp('cospend')
		//    ->setUser($userid)
		//    ->setDateTime(new DateTime())
		//    ->setObject('addusershare', $projectid)
		//    ->setSubject('add_user_share', [$fromUserId, $projectInfo['name']])
		//    ->addAction($acceptAction)
		//    ->addAction($declineAction)
		//    ;

		//$manager->notify($notification);

		return $insertedShare->jsonSerialize();
	}

	/**
	 * Change shared access permissions
	 *
	 * @param string $projectId
	 * @param int $shId
	 * @param int $accessLevel
	 * @return array
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function editShareAccessLevel(string $projectId, int $shId, int $accessLevel): array {
		try {
			$share = $this->shareMapper->getProjectShareById($projectId, $shId);
			$share->setAccessLevel($accessLevel);
			$this->shareMapper->update($share);
			return ['success' => true];
		} catch (DoesNotExistException $e) {
			return ['message' => $this->l10n->t('No such share')];
		}
	}

	/**
	 * Change shared access permissions
	 *
	 * @param string $projectId
	 * @param int $shId
	 * @param string|null $label
	 * @param string|null $password
	 * @return array
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function editShareAccess(string $projectId, int $shId, ?string $label = null, ?string $password = null): array {
		if (is_null($label) && is_null($password)) {
			return ['message' => $this->l10n->t('Invalid values')];
		}
		try {
			$share = $this->shareMapper->getProjectShareById($projectId, $shId);
			if ($label !== null) {
				$share->setLabel($label === '' ? null : $label);
			}
			if ($password !== null) {
				$share->setPassword($password === '' ? null : $password);
			}
			$this->shareMapper->update($share);
			return ['success' => true];
		} catch (DoesNotExistException $e) {
			return ['message' => $this->l10n->t('No such share')];
		}
	}

	/**
	 * Delete user shared access
	 *
	 * @param string $projectId
	 * @param int $shId
	 * @param string|null $fromUserId
	 * @return array
	 * @throws CospendBasicException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteUserShare(string $projectId, int $shId, ?string $fromUserId = null): array {
		try {
			$share = $this->shareMapper->getProjectShareById($projectId, $shId, Share::TYPE_USER);
		} catch (DoesNotExistException $e) {
			return ['message' => $this->l10n->t('No such share')];
		}
		$dbUserId = $share->getUserId();
		$this->shareMapper->delete($share);

		// activity
		$projectObj = $this->projectMapper->find($projectId);
		$this->activityManager->triggerEvent(
			ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
			ActivityManager::SUBJECT_PROJECT_UNSHARE,
			['who' => $dbUserId, 'type' => Application::SHARE_TYPE_USER]
		);

		// SEND NOTIFICATION
		if (!is_null($fromUserId)) {
			$projectInfo = $this->getProjectInfo($projectId);

			$manager = $this->notificationManager;
			$notification = $manager->createNotification();

			$acceptAction = $notification->createAction();
			$acceptAction->setLabel('accept')
				->setLink('/apps/cospend', 'GET');

			$declineAction = $notification->createAction();
			$declineAction->setLabel('decline')
				->setLink('/apps/cospend', 'GET');

			$notification->setApp('cospend')
				->setUser($dbUserId)
				->setDateTime(new DateTime())
				->setObject('deleteusershare', $projectId)
				->setSubject('delete_user_share', [$fromUserId, $projectInfo['name']])
				->addAction($acceptAction)
				->addAction($declineAction)
			;

			$manager->notify($notification);
		}

		return ['success' => true];
	}

	/**
	 * Delete public shared access
	 *
	 * @param string $projectId
	 * @param int $shId
	 * @return array
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deletePublicShare(string $projectId, int $shId): array {
		try {
			$share = $this->shareMapper->getProjectShareById($projectId, $shId, Share::TYPE_PUBLIC_LINK);
		} catch (DoesNotExistException $e) {
			return ['message' => $this->l10n->t('No such share')];
		}
		$this->shareMapper->delete($share);

		//// activity
		//$projectObj = $this->projectMapper->find($projectid);
		//$this->activityManager->triggerEvent(
		//    ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
		//    ActivityManager::SUBJECT_PROJECT_UNSHARE,
		//    ['who' => $dbuserId, 'type' => 'u']
		//);

		//// SEND NOTIFICATION
		//$projectInfo = $this->getProjectInfo($projectid);

		//$manager = $this->notificationManager;
		//$notification = $manager->createNotification();

		//$acceptAction = $notification->createAction();
		//$acceptAction->setLabel('accept')
		//    ->setLink('/apps/cospend', 'GET');

		//$declineAction = $notification->createAction();
		//$declineAction->setLabel('decline')
		//    ->setLink('/apps/cospend', 'GET');

		//$notification->setApp('cospend')
		//    ->setUser($dbuserId)
		//    ->setDateTime(new DateTime())
		//    ->setObject('deleteusershare', $projectid)
		//    ->setSubject('delete_user_share', [$fromUserId, $projectInfo['name']])
		//    ->addAction($acceptAction)
		//    ->addAction($declineAction)
		//    ;

		//$manager->notify($notification);

		return ['success' => true];
	}

	/**
	 * Add group shared access
	 *
	 * @param string $projectId
	 * @param string $groupId
	 * @param string|null $fromUserId
	 * @param int $accessLevel
	 * @return array
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function createGroupShare(
		string $projectId, string $groupId, ?string $fromUserId = null, int $accessLevel = Application::ACCESS_LEVEL_PARTICIPANT,
	): array {
		if (!$this->groupManager->groupExists($groupId)) {
			return ['message' => $this->l10n->t('No such group')];
		}

		try {
			$existingShare = $this->shareMapper->getShareByProjectAndUser($projectId, $groupId, Share::TYPE_GROUP);
			return ['message' => $this->l10n->t('Already shared with this group')];
		} catch (DoesNotExistException $e) {
		}

		$share = new Share();
		$share->setProjectId($projectId);
		$share->setUserId($groupId);
		$share->setType(Share::TYPE_GROUP);
		$share->setAccessLevel($accessLevel);
		$insertedShare = $this->shareMapper->insert($share);

		// activity
		$projectObj = $this->projectMapper->find($projectId);
		$this->activityManager->triggerEvent(
			ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
			ActivityManager::SUBJECT_PROJECT_SHARE,
			['who' => $groupId, 'type' => Application::SHARE_TYPE_GROUP]
		);

		return $insertedShare->jsonSerialize();
	}

	/**
	 * Delete group shared access
	 *
	 * @param string $projectId
	 * @param int $shId
	 * @param string|null $fromUserId
	 * @return array
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteGroupShare(string $projectId, int $shId, ?string $fromUserId = null): array {
		try {
			$share = $this->shareMapper->getProjectShareById($projectId, $shId, Share::TYPE_GROUP);
		} catch (DoesNotExistException $e) {
			return ['message' => $this->l10n->t('No such share')];
		}
		$dbGroupId = $share->getUserId();
		$this->shareMapper->delete($share);
		// activity
		$projectObj = $this->projectMapper->find($projectId);
		$this->activityManager->triggerEvent(
			ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
			ActivityManager::SUBJECT_PROJECT_UNSHARE,
			['who' => $dbGroupId, 'type' => Application::SHARE_TYPE_GROUP]
		);

		return ['success' => true];
	}

	/**
	 * Add circle shared access
	 *
	 * @param string $projectId
	 * @param string $circleId
	 * @param string|null $fromUserId
	 * @param int $accesslevel
	 * @return array
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function createCircleShare(
		string $projectId, string $circleId, ?string $fromUserId = null, int $accesslevel = Application::ACCESS_LEVEL_PARTICIPANT,
	): array {
		// check if circleId exists
		$circlesEnabled = $this->appManager->isEnabledForUser('circles');
		if (!$circlesEnabled) {
			return ['message' => $this->l10n->t('Circles app is not enabled')];
		}

		try {
			$circlesManager = \OC::$server->get(\OCA\Circles\CirclesManager::class);
			$circlesManager->startSuperSession();
		} catch (Exception $e) {
			return ['message' => $this->l10n->t('Impossible to get the circle manager')];
		}

		try {
			$circle = $circlesManager->getCircle($circleId);
			$circleName = $circle->getDisplayName();
		} catch (\OCA\Circles\Exceptions\CircleNotFoundException $e) {
			$circlesManager->stopSession();
			return ['message' => $this->l10n->t('No such circle')];
		}

		try {
			$existingShare = $this->shareMapper->getShareByProjectAndUser($projectId, $circleId, Share::TYPE_CIRCLE);
			$circlesManager->stopSession();
			return ['message' => $this->l10n->t('Already shared with this circle')];
		} catch (DoesNotExistException $e) {
		}

		$share = new Share();
		$share->setProjectId($projectId);
		$share->setUserId($circleId);
		$share->setType(Share::TYPE_CIRCLE);
		$share->setAccessLevel($accesslevel);
		$insertedShare = $this->shareMapper->insert($share);

		// activity
		$projectObj = $this->projectMapper->find($projectId);
		$this->activityManager->triggerEvent(
			ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
			ActivityManager::SUBJECT_PROJECT_SHARE,
			['who' => $circleId, 'type' => Application::SHARE_TYPE_CIRCLE]
		);

		$circlesManager->stopSession();
		$jsonInsertedShare = $insertedShare->jsonSerialize();
		$jsonInsertedShare['name'] = $circleName;
		return $jsonInsertedShare;
	}

	/**
	 * Delete circle shared access
	 *
	 * @param string $projectId
	 * @param int $shId
	 * @param string|null $fromUserId
	 * @return array
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function deleteCircleShare(string $projectId, int $shId, ?string $fromUserId = null): array {
		try {
			$share = $this->shareMapper->getProjectShareById($projectId, $shId, Share::TYPE_CIRCLE);
			$dbCircleId = $share->getUserId();
			$this->shareMapper->delete($share);

			// activity
			$projectObj = $this->projectMapper->find($projectId);
			$this->activityManager->triggerEvent(
				ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
				ActivityManager::SUBJECT_PROJECT_UNSHARE,
				['who' => $dbCircleId, 'type' => Application::SHARE_TYPE_CIRCLE]
			);

			return ['success' => true];
		} catch (DoesNotExistException $e) {
			return ['message' => $this->l10n->t('No such share')];
		}
	}
}
