<?php
require_once 'youtube_entry.php';

class Database {
    private readonly SQLite3 $conn;
    private const _INIT_SQL = 'database/init.sql';

    public function __construct($dbName) {
        $this->conn = new SQLite3($dbName);

        if (file_exists($dbName) && filesize($dbName) === 0) {
            $this->_init();
        }
    }

    private function _init() {
        $sql = file_get_contents(self::_INIT_SQL);
        $this->_exec($sql);
    }

    public function getNewContentIds(array $contentIds):array {
        $sql = 'SELECT content_id FROM feed';

        $result = $this->_query($sql);
        if ($result === false) {
            return $contentIds;
        }

        $dbContentIds = [];
        while($record = $result->fetchArray(SQLITE3_ASSOC)) {
            $dbContentIds[] = $record['content_id'];
        }

        $newContentIds = array_diff($contentIds, $dbContentIds);
        $newContentIds = array_values($newContentIds);

        return $newContentIds;
    }

    public function insertYoutubeEntry(YoutubeEntry $entry) {
        $sql = 'INSERT INTO feed(channel_id, channel_name, content_id, content_type, publish_date, check_date) VALUES(\'' . $entry->channelId . '\', \'' . $entry->channelName . '\', \'' . $entry->contentId . '\', \'' . $entry->contentType . '\', \'' . $entry->publishDate->format('Y/m/d H:i:s') . '\', \'' . (new DateTime())->format('Y/m/d H:i:s') . '\')';
        $this->_exec($sql);
    }

    private function _exec($query) {
        $this->conn->exec($query);
    }

    private function _query($query): SQLite3Result {
        return $this->conn->query($query);
    }
}