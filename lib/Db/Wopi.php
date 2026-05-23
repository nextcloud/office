<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method void setOwnerUid(string $uid)
 * @method string|null getOwnerUid()
 * @method void setEditorUid(?string $uid)
 * @method string|null getEditorUid()
 * @method void setGuestDisplayname(?string $name)
 * @method string|null getGuestDisplayname()
 * @method void setFileid(int $fileid)
 * @method int getFileid()
 * @method void setVersion(string $version)
 * @method string getVersion()
 * @method void setCanwrite(bool $canwrite)
 * @method bool getCanwrite()
 * @method void setHideDownload(bool $hide)
 * @method bool getHideDownload()
 * @method void setServerHost(string $host)
 * @method string getServerHost()
 * @method void setToken(string $token)
 * @method string getToken()
 * @method void setExpiry(int $expiry)
 * @method int getExpiry()
 */
class Wopi extends Entity {
	public const TOKEN_TYPE_USER = 0;
	public const TOKEN_TYPE_GUEST = 1;

	/** @var string|null */
	protected $ownerUid;

	/** @var string|null */
	protected $editorUid;

	/** @var string|null */
	protected $guestDisplayname;

	/** @var int */
	protected $fileid;

	/** @var string */
	protected $version = '0';

	/** @var bool */
	protected $canwrite = false;

	/** @var bool */
	protected $hideDownload = false;

	/** @var string */
	protected $serverHost;

	/** @var string */
	protected $token;

	/** @var int|null */
	protected $expiry;

	public function __construct() {
		$this->addType('ownerUid', Types::STRING);
		$this->addType('editorUid', Types::STRING);
		$this->addType('guestDisplayname', Types::STRING);
		$this->addType('fileid', Types::INTEGER);
		$this->addType('version', Types::STRING);
		$this->addType('canwrite', Types::BOOLEAN);
		$this->addType('hideDownload', Types::BOOLEAN);
		$this->addType('serverHost', Types::STRING);
		$this->addType('token', Types::STRING);
		$this->addType('expiry', Types::INTEGER);
	}

	public function isGuest(): bool {
		return $this->guestDisplayname !== null && $this->editorUid === null;
	}

	public function isExpired(): bool {
		return $this->expiry !== null && $this->expiry < time();
	}

	/**
	 * Return the UID that should be used for file access.
	 * Guests use the file owner's UID for NC file operations.
	 */
	public function getUserForFileAccess(): string {
		return $this->isGuest() ? (string)$this->ownerUid : (string)$this->editorUid;
	}
}
