<?php

class FreshRSS_FeedDAO extends Minz_ModelPdo {

	protected function addColumn(string $name) {
		if ($this->pdo->inTransaction()) {
			$this->pdo->commit();
		}
		Minz_Log::warning(__method__ . ': ' . $name);
		try {
			if ($name === 'kind') {	//v1.20.0
				return $this->pdo->exec('ALTER TABLE `_feed` ADD COLUMN kind SMALLINT DEFAULT 0') !== false;
			} elseif ($name === 'attributes') {	//v1.11.0
				return $this->pdo->exec('ALTER TABLE `_feed` ADD COLUMN attributes TEXT') !== false;
			}
		} catch (Exception $e) {
			Minz_Log::error(__method__ . ' error: ' . $e->getMessage());
		}
		return false;
	}

	protected function autoUpdateDb(array $errorInfo) {
		if (isset($errorInfo[0])) {
			if ($errorInfo[0] === FreshRSS_DatabaseDAO::ER_BAD_FIELD_ERROR || $errorInfo[0] === FreshRSS_DatabaseDAOPGSQL::UNDEFINED_COLUMN) {
				$errorLines = explode("\n", $errorInfo[2], 2);	// The relevant column name is on the first line, other lines are noise
				foreach (['attributes', 'kind'] as $column) {
					if (stripos($errorLines[0], $column) !== false) {
						return $this->addColumn($column);
					}
				}
			}
		}
		return false;
	}

	/** @return int|false */
	public function addFeed(array $valuesTmp) {
		$sql = 'INSERT INTO `_feed` (url, kind, category, name, website, description, `lastUpdate`, priority, `pathEntries`, `httpAuth`, error, ttl, attributes)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
		$stm = $this->pdo->prepare($sql);

		$valuesTmp['url'] = safe_ascii($valuesTmp['url']);
		$valuesTmp['website'] = safe_ascii($valuesTmp['website']);
		if (!isset($valuesTmp['pathEntries'])) {
			$valuesTmp['pathEntries'] = '';
		}
		if (!isset($valuesTmp['attributes'])) {
			$valuesTmp['attributes'] = [];
		}

		$values = array(
			$valuesTmp['url'],
			$valuesTmp['kind'] ?? FreshRSS_Feed::KIND_RSS,
			$valuesTmp['category'],
			mb_strcut(trim($valuesTmp['name']), 0, FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE, 'UTF-8'),
			$valuesTmp['website'],
			sanitizeHTML($valuesTmp['description'], '', 1023),
			$valuesTmp['lastUpdate'],
			isset($valuesTmp['priority']) ? intval($valuesTmp['priority']) : FreshRSS_Feed::PRIORITY_MAIN_STREAM,
			mb_strcut($valuesTmp['pathEntries'], 0, 511, 'UTF-8'),
			base64_encode($valuesTmp['httpAuth']),
			isset($valuesTmp['error']) ? intval($valuesTmp['error']) : 0,
			isset($valuesTmp['ttl']) ? intval($valuesTmp['ttl']) : FreshRSS_Feed::TTL_DEFAULT,
			is_string($valuesTmp['attributes']) ? $valuesTmp['attributes'] : json_encode($valuesTmp['attributes'], JSON_UNESCAPED_SLASHES),
		);

		if ($stm && $stm->execute($values)) {
			return $this->pdo->lastInsertId('`_feed_id_seq`');
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			if ($this->autoUpdateDb($info)) {
				return $this->addFeed($valuesTmp);
			}
			Minz_Log::error('SQL error addFeed: ' . $info[2]);
			return false;
		}
	}

	/** @return int|false */
	public function addFeedObject(FreshRSS_Feed $feed) {
		// Add feed only if we don’t find it in DB
		$feed_search = $this->searchByUrl($feed->url());
		if (!$feed_search) {
			$values = array(
				'id' => $feed->id(),
				'url' => $feed->url(),
				'kind' => $feed->kind(),
				'category' => $feed->categoryId(),
				'name' => $feed->name(true),
				'website' => $feed->website(),
				'description' => $feed->description(),
				'lastUpdate' => 0,
				'pathEntries' => $feed->pathEntries(),
				'httpAuth' => $feed->httpAuth(),
				'ttl' => $feed->ttl(true),
				'attributes' => $feed->attributes(),
			);

			$id = $this->addFeed($values);
			if ($id) {
				$feed->_id($id);
				$feed->faviconPrepare();
			}

			return $id;
		} else {
			// The feed already exists so make sure it is not muted
			$feed->_ttl($feed_search->ttl());
			$feed->_mute(false);

			// Merge existing and import attributes
			$existingAttributes = $feed_search->attributes();
			$importAttributes = $feed->attributes();
			$feed->_attributes('', array_replace_recursive($existingAttributes, $importAttributes));

			// Update some values of the existing feed using the import
			$values = [
				'kind' => $feed->kind(),
				'name' => $feed->name(true),
				'website' => $feed->website(),
				'description' => $feed->description(),
				'pathEntries' => $feed->pathEntries(),
				'ttl' => $feed->ttl(true),
				'attributes' => $feed->attributes(),
			];

			if (!$this->updateFeed($feed_search->id(), $values)) {
				return false;
			}

			return $feed_search->id();
		}
	}

	/** @return int|false */
	public function updateFeed(int $id, array $valuesTmp) {
		if (isset($valuesTmp['name'])) {
			$valuesTmp['name'] = mb_strcut(trim($valuesTmp['name']), 0, FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE, 'UTF-8');
		}
		if (isset($valuesTmp['url'])) {
			$valuesTmp['url'] = safe_ascii($valuesTmp['url']);
		}
		if (isset($valuesTmp['website'])) {
			$valuesTmp['website'] = safe_ascii($valuesTmp['website']);
		}

		$set = '';
		foreach ($valuesTmp as $key => $v) {
			$set .= '`' . $key . '`=?, ';

			if ($key === 'httpAuth') {
				$valuesTmp[$key] = base64_encode($v);
			} elseif ($key === 'attributes') {
				$valuesTmp[$key] = is_string($valuesTmp[$key]) ? $valuesTmp[$key] : json_encode($valuesTmp[$key], JSON_UNESCAPED_SLASHES);
			}
		}
		$set = substr($set, 0, -2);

		$sql = 'UPDATE `_feed` SET ' . $set . ' WHERE id=?';
		$stm = $this->pdo->prepare($sql);

		foreach ($valuesTmp as $v) {
			$values[] = $v;
		}
		$values[] = $id;

		if ($stm && $stm->execute($values)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			if ($this->autoUpdateDb($info)) {
				return $this->updateFeed($id, $valuesTmp);
			}
			Minz_Log::error('SQL error updateFeed: ' . $info[2] . ' for feed ' . $id);
			return false;
		}
	}

	public function updateFeedAttribute(FreshRSS_Feed $feed, string $key, $value) {
		$feed->_attributes($key, $value);
		return $this->updateFeed(
				$feed->id(),
				array('attributes' => $feed->attributes())
			);
	}

	/**
	 * @see updateCachedValue()
	 */
	public function updateLastUpdate(int $id, bool $inError = false, int $mtime = 0) {
		$sql = 'UPDATE `_feed` SET `lastUpdate`=?, error=? WHERE id=?';
		$values = array(
			$mtime <= 0 ? time() : $mtime,
			$inError ? 1 : 0,
			$id,
		);
		$stm = $this->pdo->prepare($sql);

		if ($stm && $stm->execute($values)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::warning(__METHOD__ . ' error: ' . $sql . ' : ' . json_encode($info));
			return false;
		}
	}

	public function mute(int $id, bool $value = true) {
		$sql = 'UPDATE `_feed` SET ttl=' . ($value ? '-' : '') . 'ABS(ttl) WHERE id=' . intval($id);
		return $this->pdo->exec($sql);
	}

	public function changeCategory(int $idOldCat, int $idNewCat) {
		$catDAO = FreshRSS_Factory::createCategoryDao();
		$newCat = $catDAO->searchById($idNewCat);
		if (!$newCat) {
			$newCat = $catDAO->getDefault();
		}

		$sql = 'UPDATE `_feed` SET category=? WHERE category=?';
		$stm = $this->pdo->prepare($sql);

		$values = array(
			$newCat->id(),
			$idOldCat
		);

		if ($stm && $stm->execute($values)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error changeCategory: ' . $info[2]);
			return false;
		}
	}

	/** @return int|false */
	public function deleteFeed(int $id) {
		$sql = 'DELETE FROM `_feed` WHERE id=?';
		$stm = $this->pdo->prepare($sql);

		$values = array($id);

		if ($stm && $stm->execute($values)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error deleteFeed: ' . $info[2]);
			return false;
		}
	}

	/**
	 * @param bool|null $muted to include only muted feeds
	 * @return int|false
	 */
	public function deleteFeedByCategory(int $id, $muted = null) {
		$sql = 'DELETE FROM `_feed` WHERE category=?';
		if ($muted) {
			$sql .= ' AND ttl < 0';
		}
		$stm = $this->pdo->prepare($sql);

		$values = array($id);

		if ($stm && $stm->execute($values)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error deleteFeedByCategory: ' . $info[2]);
			return false;
		}
	}

	public function selectAll() {
		$sql = <<<'SQL'
SELECT id, url, kind, category, name, website, description, `lastUpdate`,
	priority, `pathEntries`, `httpAuth`, error, ttl, attributes
FROM `_feed`
SQL;
		$stm = $this->pdo->query($sql);
		while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
			yield $row;
		}
	}

	public function searchById(int $id): ?FreshRSS_Feed {
		$sql = 'SELECT * FROM `_feed` WHERE id=:id';
		$stm = $this->pdo->prepare($sql);
		$stm->bindParam(':id', $id, PDO::PARAM_INT);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);
		$feed = self::daoToFeed($res);
		return $feed[$id] ?? null;
	}

	/**
	 * @return FreshRSS_Feed|null
	 */
	public function searchByUrl(string $url) {
		$sql = 'SELECT * FROM `_feed` WHERE url=?';
		$stm = $this->pdo->prepare($sql);

		$values = array($url);

		$stm->execute($values);
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);
		$feed = current(self::daoToFeed($res));
		return $feed == false ? null : $feed;
	}

	public function listFeedsIds(): array {
		$sql = 'SELECT id FROM `_feed`';
		$stm = $this->pdo->query($sql);
		return $stm->fetchAll(PDO::FETCH_COLUMN, 0);
	}

	/**
	 * @return array<FreshRSS_Feed>
	 */
	public function listFeeds(): array {
		$sql = 'SELECT * FROM `_feed` ORDER BY name';
		$stm = $this->pdo->query($sql);
		return self::daoToFeed($stm->fetchAll(PDO::FETCH_ASSOC));
	}

	public function listFeedsNewestItemUsec($id_feed = null) {
		$sql = 'SELECT id_feed, MAX(id) as newest_item_us FROM `_entry` ';
		if ($id_feed === null) {
			$sql .= 'GROUP BY id_feed';
		} else {
			$sql .= 'WHERE id_feed=' . intval($id_feed);
		}
		$stm = $this->pdo->query($sql);
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);
		$newestItemUsec = [];
		foreach ($res as $line) {
			$newestItemUsec['f_' . $line['id_feed']] = $line['newest_item_us'];
		}
		return $newestItemUsec;
	}

	/**
	 * Use $defaultCacheDuration == -1 to return all feeds, without filtering them by TTL.
	 * @return array<FreshRSS_Feed>
	 */
	public function listFeedsOrderUpdate(int $defaultCacheDuration = 3600, int $limit = 0) {
		$this->updateTTL();
		$sql = 'SELECT id, url, kind, name, website, `lastUpdate`, `pathEntries`, `httpAuth`, ttl, attributes '
			. 'FROM `_feed` '
			. ($defaultCacheDuration < 0 ? '' : 'WHERE ttl >= ' . FreshRSS_Feed::TTL_DEFAULT
			. ' AND `lastUpdate` < (' . (time() + 60)
			. '-(CASE WHEN ttl=' . FreshRSS_Feed::TTL_DEFAULT . ' THEN ' . intval($defaultCacheDuration) . ' ELSE ttl END)) ')
			. 'ORDER BY `lastUpdate` '
			. ($limit < 1 ? '' : 'LIMIT ' . intval($limit));
		$stm = $this->pdo->query($sql);
		if ($stm !== false) {
			return self::daoToFeed($stm->fetchAll(PDO::FETCH_ASSOC));
		} else {
			$info = $this->pdo->errorInfo();
			if ($this->autoUpdateDb($info)) {
				return $this->listFeedsOrderUpdate($defaultCacheDuration, $limit);
			}
			Minz_Log::error('SQL error listFeedsOrderUpdate: ' . $info[2]);
			return array();
		}
	}

	public function listTitles(int $id, int $limit = 0) {
		$sql = 'SELECT title FROM `_entry` WHERE id_feed=:id_feed ORDER BY id DESC'
			. ($limit < 1 ? '' : ' LIMIT ' . intval($limit));

		$stm = $this->pdo->prepare($sql);
		$stm->bindParam(':id_feed', $id, PDO::PARAM_INT);

		if ($stm && $stm->execute()) {
			return $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		}
		return false;
	}

	/**
	 * @param bool|null $muted to include only muted feeds
	 * @return array<FreshRSS_Feed>
	 */
	public function listByCategory(int $cat, $muted = null): array {
		$sql = 'SELECT * FROM `_feed` WHERE category=?';
		if ($muted) {
			$sql .= ' AND ttl < 0';
		}
		$stm = $this->pdo->prepare($sql);

		$stm->execute(array($cat));

		$feeds = self::daoToFeed($stm->fetchAll(PDO::FETCH_ASSOC));

		usort($feeds, function ($a, $b) {
			return strnatcasecmp($a->name(), $b->name());
		});

		return $feeds;
	}

	public function countEntries(int $id) {
		$sql = 'SELECT COUNT(*) AS count FROM `_entry` WHERE id_feed=?';
		$stm = $this->pdo->prepare($sql);
		$values = array($id);
		$stm->execute($values);
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);

		return $res[0]['count'];
	}

	public function countNotRead(int $id) {
		$sql = 'SELECT COUNT(*) AS count FROM `_entry` WHERE id_feed=? AND is_read=0';
		$stm = $this->pdo->prepare($sql);
		$values = array($id);
		$stm->execute($values);
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);

		return $res[0]['count'];
	}

	/**
	 * @return int|false
	 */
	public function updateCachedValues(int $id = 0) {
		//2 sub-requests with FOREIGN KEY(e.id_feed), INDEX(e.is_read) faster than 1 request with GROUP BY or CASE
		$sql = 'UPDATE `_feed` '
			. 'SET `cache_nbEntries`=(SELECT COUNT(e1.id) FROM `_entry` e1 WHERE e1.id_feed=`_feed`.id),'
			. '`cache_nbUnreads`=(SELECT COUNT(e2.id) FROM `_entry` e2 WHERE e2.id_feed=`_feed`.id AND e2.is_read=0)'
			. ($id != 0 ? ' WHERE id=:id' : '');
		$stm = $this->pdo->prepare($sql);
		if ($stm && $id != 0) {
			$stm->bindParam(':id', $id, PDO::PARAM_INT);
		}

		if ($stm && $stm->execute()) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error updateCachedValue: ' . $info[2]);
			return false;
		}
	}

	/**
	 * Remember to call updateCachedValues() after calling this function
	 * @return int|false number of lines affected or false in case of error
	 */
	public function keepMaxUnread(int $id, int $n) {
		//Double SELECT for MySQL workaround ERROR 1093 (HY000)
		$sql = <<<'SQL'
UPDATE `_entry` SET is_read=1
WHERE id_feed=:id_feed1 AND is_read=0 AND id <= (SELECT e3.id FROM (
	SELECT e2.id FROM `_entry` e2
	WHERE e2.id_feed=:id_feed2 AND e2.is_read=0
	ORDER BY e2.id DESC
	LIMIT 1
	OFFSET :limit) e3)
SQL;

		if (($stm = $this->pdo->prepare($sql)) &&
			$stm->bindParam(':id_feed1', $id, PDO::PARAM_INT) &&
			$stm->bindParam(':id_feed2', $id, PDO::PARAM_INT) &&
			$stm->bindParam(':limit', $n, PDO::PARAM_INT) &&
			$stm->execute()) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error keepMaxUnread: ' . json_encode($info));
			return false;
		}
	}

	/**
	 * Remember to call updateCachedValues() after calling this function
	 * @return int|false number of lines affected or false in case of error
	 */
	public function markAsReadUponGone(int $id) {
		//Double SELECT for MySQL workaround ERROR 1093 (HY000)
		$sql = <<<'SQL'
UPDATE `_entry` SET is_read=1
WHERE id_feed=:id_feed1 AND is_read=0 AND `lastSeen` < (SELECT e3.maxlastseen FROM (
	SELECT MAX(e2.`lastSeen`) AS maxlastseen FROM `_entry` e2 WHERE e2.id_feed = :id_feed2) e3)
SQL;

		if (($stm = $this->pdo->prepare($sql)) &&
			$stm->bindParam(':id_feed1', $id, PDO::PARAM_INT) &&
			$stm->bindParam(':id_feed2', $id, PDO::PARAM_INT) &&
			$stm->execute()) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markAsReadUponGone: ' . json_encode($info));
			return false;
		}
	}

	/**
	 * @return int|false
	 */
	public function truncate(int $id) {
		$sql = 'DELETE FROM `_entry` WHERE id_feed=:id';
		$stm = $this->pdo->prepare($sql);
		$stm->bindParam(':id', $id, PDO::PARAM_INT);
		$this->pdo->beginTransaction();
		if (!($stm && $stm->execute())) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error truncate: ' . $info[2]);
			$this->pdo->rollBack();
			return false;
		}
		$affected = $stm->rowCount();

		$sql = 'UPDATE `_feed` '
			 . 'SET `cache_nbEntries`=0, `cache_nbUnreads`=0, `lastUpdate`=0 WHERE id=:id';
		$stm = $this->pdo->prepare($sql);
		$stm->bindParam(':id', $id, PDO::PARAM_INT);
		if (!($stm && $stm->execute())) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error truncate: ' . $info[2]);
			$this->pdo->rollBack();
			return false;
		}

		$this->pdo->commit();
		return $affected;
	}

	public function purge() {
		$sql = 'DELETE FROM `_entry`';
		$stm = $this->pdo->prepare($sql);
		$this->pdo->beginTransaction();
		if (!($stm && $stm->execute())) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error truncate: ' . $info[2]);
			$this->pdo->rollBack();
			return false;
		}

		$sql = 'UPDATE `_feed` SET `cache_nbEntries` = 0, `cache_nbUnreads` = 0';
		$stm = $this->pdo->prepare($sql);
		if (!($stm && $stm->execute())) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error truncate: ' . $info[2]);
			$this->pdo->rollBack();
			return false;
		}

		$this->pdo->commit();
	}

	/**
	 * @return array<FreshRSS_Feed>
	 */
	public static function daoToFeed($listDAO, $catID = null): array {
		$list = array();

		if (!is_array($listDAO)) {
			$listDAO = array($listDAO);
		}

		foreach ($listDAO as $key => $dao) {
			if (!isset($dao['name'])) {
				continue;
			}
			if (isset($dao['id'])) {
				$key = $dao['id'];
			}
			if ($catID === null) {
				$category = isset($dao['category']) ? $dao['category'] : 0;
			} else {
				$category = $catID;
			}

			$myFeed = new FreshRSS_Feed($dao['url'] ?? '', false);
			$myFeed->_kind($dao['kind'] ?? FreshRSS_Feed::KIND_RSS);
			$myFeed->_categoryId($category);
			$myFeed->_name($dao['name']);
			$myFeed->_website($dao['website'] ?? '', false);
			$myFeed->_description($dao['description'] ?? '');
			$myFeed->_lastUpdate($dao['lastUpdate'] ?? 0);
			$myFeed->_priority($dao['priority'] ?? 10);
			$myFeed->_pathEntries($dao['pathEntries'] ?? '');
			$myFeed->_httpAuth(base64_decode($dao['httpAuth'] ?? ''));
			$myFeed->_error($dao['error'] ?? 0);
			$myFeed->_ttl($dao['ttl'] ?? FreshRSS_Feed::TTL_DEFAULT);
			$myFeed->_attributes('', $dao['attributes'] ?? '');
			$myFeed->_nbNotRead($dao['cache_nbUnreads'] ?? 0);
			$myFeed->_nbEntries($dao['cache_nbEntries'] ?? 0);
			if (isset($dao['id'])) {
				$myFeed->_id($dao['id']);
			}
			$list[$key] = $myFeed;
		}

		return $list;
	}

	public function updateTTL() {
		$sql = 'UPDATE `_feed` SET ttl=:new_value WHERE ttl=:old_value';
		$stm = $this->pdo->prepare($sql);
		if (!($stm && $stm->execute(array(':new_value' => FreshRSS_Feed::TTL_DEFAULT, ':old_value' => -2)))) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL warning updateTTL 1: ' . $info[2] . ' ' . $sql);

			$sql2 = 'ALTER TABLE `_feed` ADD COLUMN ttl INT NOT NULL DEFAULT ' . FreshRSS_Feed::TTL_DEFAULT;	//v0.7.3
			$stm = $this->pdo->query($sql2);
			if ($stm === false) {
				$info = $this->pdo->errorInfo();
				Minz_Log::error('SQL error updateTTL 2: ' . $info[2] . ' ' . $sql2);
			}
		} else {
			$stm->execute(array(':new_value' => -3600, ':old_value' => -1));
		}
	}

	/**
	 * @return int|false
	 */
	public function count() {
		$sql = 'SELECT COUNT(e.id) AS count FROM `_feed` e';
		$stm = $this->pdo->query($sql);
		if ($stm == false) {
			return false;
		}
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		return isset($res[0]) ? $res[0] : 0;
	}
}
