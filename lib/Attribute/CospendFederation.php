<?php

declare(strict_types=1);

namespace OCA\Cospend\Attribute;

use Attribute;

#[Attribute]
class CospendFederation {
	/**
	 * Attribute for controller methods that support federated projects
	 * This requires the method to have a $projectId parameter. If the project is federated, the federation service will
	 * be used
	 */
	public function __construct(
	) {
	}
}
