<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cospend\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method void setProjectid(string $projectid)
 * @method string getProjectid()
 * @method void setName(string|null $name)
 * @method string|null getName()
 * @method void setColor(string|null $color)
 * @method string|null getColor()
 * @method void setEncodedIcon(string|null $encodedIcon)
 * @method string|null getEncodedIcon()
 * @method void setOrder(int $order)
 * @method int getOrder()
 */
class Category extends Entity implements \JsonSerializable {
	protected string $projectid = '';
	protected ?string $name = null;
	protected ?string $color = null;
	protected ?string $encodedIcon = null;
	protected int $order = 0;

	public function __construct() {
		$this->addType('projectid', 'string');
		$this->addType('name', 'string');
		$this->addType('color', 'string');
		$this->addType('encoded_icon', 'string');
		$this->addType('order', 'int');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'projectid' => $this->getProjectid(),
			'name' => $this->getName(),
			'color' => $this->getColor(),
			'icon' => $this->getEncodedIcon() === null ? null : urldecode($this->getEncodedIcon()),
			'order' => $this->getOrder(),
		];
	}
}