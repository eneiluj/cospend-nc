<?php

namespace OCA\Cospend\Migration;

use Closure;
use Doctrine\DBAL\Schema\SchemaException;
use OCP\DB\Exception;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version010503Date20221030201200 extends SimpleMigrationStep {
	/**
	 * @var IDBConnection
	 */
	private IDBConnection $connection;

	/**
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @throws Exception
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		$queryBuilder = $this->connection->getQueryBuilder();

		// get existing project ids
		$queryBuilder->select('id')
			->from('cospend_projects');
		$result = $queryBuilder->executeQuery();

		$existingProjectIds = [];
		while ($row = $result->fetch()) {
			$existingProjectIds[] = $queryBuilder->createNamedParameter($row['id'], IQueryBuilder::PARAM_STR);
		}

		$result->closeCursor();
		$queryBuilder->resetQueryParts();

		// delete bills without project
		$queryBuilder->delete('cospend_bills')->where(
			$queryBuilder->expr()->notIn('projectid', $existingProjectIds)
		);
		$queryBuilder->executeStatement();
		$queryBuilder->resetQueryParts();

		// get existing bill ids
		$queryBuilder->select('id')
			->from('cospend_bills');
		$result = $queryBuilder->executeQuery();

		$existingBillIds = [];
		while ($row = $result->fetch()) {
			$existingBillIds[] = $queryBuilder->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT);
		}

		$result->closeCursor();
		$queryBuilder->resetQueryParts();

		// delete bill owers without bill
		$queryBuilder->delete('cospend_bill_owers')->where(
			$queryBuilder->expr()->notIn('billid', $existingBillIds)
		);
		$queryBuilder->executeStatement();
		$queryBuilder->resetQueryParts();
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 * @throws SchemaException
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$schema->getTable('cospend_bills')
			->addForeignKeyConstraint(
				$schema->getTable('cospend_projects'),
				['projectid'],
				['id'],
				['onDelete' => 'CASCADE', 'onUpdate' => 'CASCADE']
			);

		$schema->getTable('cospend_bill_owers')
			->addForeignKeyConstraint(
				$schema->getTable('cospend_bills'),
				['billid'],
				['id'],
				['onDelete' => 'CASCADE', 'onUpdate' => 'CASCADE']
			);


		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}
}
