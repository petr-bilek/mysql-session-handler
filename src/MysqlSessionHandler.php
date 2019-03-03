<?php
declare(strict_types = 1);

namespace Pematon\Session;

use Nette;

/**
 * Storing session to database.
 * Inspired by: https://github.com/JedenWeb/SessionStorage/
 */
class MysqlSessionHandler implements \SessionHandlerInterface
{
	private $tableName;

	/** @var Nette\Database\Context */
	private $context;

	private $lockId;

	private $idHashes = [];

	public function __construct(Nette\Database\Context $context)
	{
		$this->context = $context;
	}

	public function setTableName(string $tableName): void
	{
		$this->tableName = $tableName;
	}

	protected function hash(string $id, bool $rawOutput = TRUE): string
	{
		if (!isset($this->idHashes[$id])) {
			$this->idHashes[$id] = hash('sha256', $id, TRUE);
		}
		return ($rawOutput ? $this->idHashes[$id] : bin2hex($this->idHashes[$id]));
	}

	private function lock(): void
	{
		if ($this->lockId === null) {
			$this->lockId = $this->hash(session_id(), FALSE);
			while (!$this->context->query("SELECT GET_LOCK(?, 1) as `lock`", $this->lockId)->fetch()->lock);
		}
	}

	private function unlock(): void
	{
		if ($this->lockId === null) {
			return;
		}

		$this->context->query("SELECT RELEASE_LOCK(?)", $this->lockId);
		$this->lockId = null;
	}

	/**
	 * @param string $savePath
	 * @param string $name
	 * @return boolean
	 */
	public function open($savePath, $name): bool
	{
		$this->lock();

		return TRUE;
	}

	public function close(): bool
	{
		$this->unlock();

		return TRUE;
	}

	/**
	 * @param string $sessionId
	 * @return boolean
	 */
	public function destroy($sessionId): bool
	{
		$hashedSessionId = $this->hash($sessionId);

		$this->context->table($this->tableName)->where('id', $hashedSessionId)->delete();

		$this->unlock();

		return TRUE;
	}

	/**
	 * @param string $sessionId
	 * @return string
	 */
	public function read($sessionId): string
	{
		$this->lock();

		$hashedSessionId = $this->hash($sessionId);

		$row = $this->context->table($this->tableName)->get($hashedSessionId);

		if ($row) {
			return $row->data;
		}

		return '';
	}

	/**
	 * @param string $sessionId
	 * @param string $sessionData
	 * @return boolean
	 */
	public function write($sessionId, $sessionData): bool
	{
		$this->lock();

		$hashedSessionId = $this->hash($sessionId);
		$time = time();

		if ($row = $this->context->table($this->tableName)->get($hashedSessionId)) {
			if ($row->data !== $sessionData) {
				$row->update(array(
					'timestamp' => $time,
					'data' => $sessionData,
				));
			} else if ($time - $row->timestamp > 300) {
				// Optimization: When data has not been changed, only update
				// the timestamp after 5 minutes.
				$row->update(array(
					'timestamp' => $time,
				));
			}
		} else {
			$this->context->table($this->tableName)->insert(array(
				'id' => $hashedSessionId,
				'timestamp' => $time,
				'data' => $sessionData,
			));
		}

		return TRUE;
	}

	/**
	 * @param  int    $maxLifeTime [description]
	 * @return boolean
	 */
	public function gc($maxLifeTime): bool
	{
		$maxTimestamp = time() - $maxLifeTime;

		// Try to avoid a conflict when running garbage collection simultaneously on two
		// MySQL servers at a very busy site in a master-master replication setup by
		// subtracting one tenth of $maxLifeTime (but at least one day) from $maxTimestamp
		// for each server with reasonably small ID except for the server with ID 1.
		//
		// In a typical master-master replication setup, the server IDs are 1 and 2.
		// There is no subtraction on server 1 and one day (or one tenth of $maxLifeTime)
		// subtraction on server 2.
		$serverId = $this->context->query("SELECT @@server_id as `server_id`")->fetch()->server_id;
		if ($serverId > 1 && $serverId < 10) {
			$maxTimestamp -= ($serverId - 1) * max(86400, $maxLifeTime / 10);
		}

		$this->context->table($this->tableName)
			->where('timestamp < ?', $maxTimestamp)
			->delete();

		return TRUE;
	}
}
