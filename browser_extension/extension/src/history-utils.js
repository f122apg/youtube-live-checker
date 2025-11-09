/**
 * @module history-utils
 * @description 履歴機能に特化したユーティリティ
 */

// ========================
// 定数
// ========================

/**
 * 表示モード
 * @enum {string}
 */
export const ViewMode = {
  GRID: 'grid',
  LIST: 'list',
  CHANNEL_GROUP: 'channel_group'
};

/**
 * ソートタイプ
 * @enum {string}
 */
export const SortType = {
  DATE_DESC: 'date-desc',
  DATE_ASC: 'date-asc',
  TITLE_ASC: 'title-asc',
  TITLE_DESC: 'title-desc',
  CHANNEL_ASC: 'channel-asc',
  CHANNEL_DESC: 'channel-desc'
};

// ========================
// グルーピング
// ========================

/**
 * チャンネルIDでグルーピング（最新の1件のみ）
 * @param {Object} histories - 履歴オブジェクト
 * @returns {Object} グルーピングされた履歴
 */
export const groupByChannelId = (histories) => {
  const storageGroup = {};

  for (const [key, data] of Object.entries(histories)) {
    // 既存のチャンネルデータがない、または現在のデータの方が新しい場合
    if (!storageGroup[data.channel_id] ||
        new Date(data.download_date) > new Date(storageGroup[data.channel_id].download_date)) {
      storageGroup[data.channel_id] = data;
    }
  }

  return storageGroup;
};

/**
 * チャンネルIDでグルーピング（全件）
 * @param {Object} histories - 履歴オブジェクト
 * @returns {Object} チャンネルごとにグルーピングされた履歴の配列
 */
export const groupAllByChannelId = (histories) => {
  const groups = {};

  for (const [key, data] of Object.entries(histories)) {
    if (!groups[data.channel_id]) {
      groups[data.channel_id] = [];
    }
    groups[data.channel_id].push(data);
  }

  // 各グループ内を日付降順でソート
  for (const channelId in groups) {
    groups[channelId].sort((a, b) => new Date(b.download_date) - new Date(a.download_date));
  }

  return groups;
};

// ========================
// ソート
// ========================

/**
 * 履歴を日付でソート
 * @param {Object} histories - 履歴オブジェクト
 * @param {boolean} [descending=true] - 降順の場合true
 * @returns {Object} ソートされた履歴
 */
export const sortByDate = (histories, descending = true) => {
  return Object.fromEntries(
    Object.entries(histories).sort(([, a], [, b]) => {
      const comparison = new Date(b.download_date) - new Date(a.download_date);
      return descending ? comparison : -comparison;
    })
  );
};

/**
 * 履歴をタイトルでソート
 * @param {Object} histories - 履歴オブジェクト
 * @param {boolean} [ascending=true] - 昇順の場合true
 * @returns {Object} ソートされた履歴
 */
export const sortByTitle = (histories, ascending = true) => {
  return Object.fromEntries(
    Object.entries(histories).sort(([, a], [, b]) => {
      const comparison = a.title.localeCompare(b.title, 'ja');
      return ascending ? comparison : -comparison;
    })
  );
};

/**
 * 履歴をチャンネル名でソート
 * @param {Object} histories - 履歴オブジェクト
 * @param {boolean} [ascending=true] - 昇順の場合true
 * @returns {Object} ソートされた履歴
 */
export const sortByChannel = (histories, ascending = true) => {
  return Object.fromEntries(
    Object.entries(histories).sort(([, a], [, b]) => {
      const comparison = a.channel_name.localeCompare(b.channel_name, 'ja');
      return ascending ? comparison : -comparison;
    })
  );
};

/**
 * ソートタイプに基づいて履歴をソート
 * @param {Object} histories - 履歴オブジェクト
 * @param {string} sortType - ソートタイプ（SortType enum）
 * @returns {Object} ソートされた履歴
 */
export const sortHistories = (histories, sortType) => {
  switch (sortType) {
    case SortType.DATE_DESC:
      return sortByDate(histories, true);
    case SortType.DATE_ASC:
      return sortByDate(histories, false);
    case SortType.TITLE_ASC:
      return sortByTitle(histories, true);
    case SortType.TITLE_DESC:
      return sortByTitle(histories, false);
    case SortType.CHANNEL_ASC:
      return sortByChannel(histories, true);
    case SortType.CHANNEL_DESC:
      return sortByChannel(histories, false);
    default:
      return histories;
  }
};

// ========================
// フィルタリング
// ========================

/**
 * タイトルでフィルタリング
 * @param {Object} histories - 履歴オブジェクト
 * @param {string} keyword - 検索キーワード
 * @returns {Object} フィルタリングされた履歴
 */
export const filterByTitle = (histories, keyword) => {
  if (!keyword) return histories;

  const lowerKeyword = keyword.toLowerCase();
  return Object.fromEntries(
    Object.entries(histories).filter(([, data]) =>
      data.title.toLowerCase().includes(lowerKeyword)
    )
  );
};

/**
 * チャンネル名でフィルタリング
 * @param {Object} histories - 履歴オブジェクト
 * @param {string} keyword - 検索キーワード
 * @returns {Object} フィルタリングされた履歴
 */
export const filterByChannel = (histories, keyword) => {
  if (!keyword) return histories;

  const lowerKeyword = keyword.toLowerCase();
  return Object.fromEntries(
    Object.entries(histories).filter(([, data]) =>
      data.channel_name.toLowerCase().includes(lowerKeyword)
    )
  );
};

/**
 * タイトルまたはチャンネル名でフィルタリング
 * @param {Object} histories - 履歴オブジェクト
 * @param {string} keyword - 検索キーワード
 * @returns {Object} フィルタリングされた履歴
 */
export const filterByKeyword = (histories, keyword) => {
  if (!keyword) return histories;

  const lowerKeyword = keyword.toLowerCase();
  return Object.fromEntries(
    Object.entries(histories).filter(([, data]) =>
      data.title.toLowerCase().includes(lowerKeyword) ||
      data.channel_name.toLowerCase().includes(lowerKeyword)
    )
  );
};

/**
 * 日付範囲でフィルタリング
 * @param {Object} histories - 履歴オブジェクト
 * @param {Date|string} startDate - 開始日
 * @param {Date|string} endDate - 終了日
 * @returns {Object} フィルタリングされた履歴
 */
export const filterByDateRange = (histories, startDate, endDate) => {
  const start = startDate ? new Date(startDate) : null;
  const end = endDate ? new Date(endDate) : null;

  if (!start && !end) return histories;

  return Object.fromEntries(
    Object.entries(histories).filter(([, data]) => {
      const date = new Date(data.download_date);
      if (start && date < start) return false;
      if (end && date > end) return false;
      return true;
    })
  );
};

// ========================
// データ変換
// ========================

/**
 * 履歴オブジェクトを配列に変換
 * @param {Object} histories - 履歴オブジェクト
 * @returns {Array} 履歴配列
 */
export const historiesToArray = (histories) => {
  return Object.values(histories);
};

/**
 * 履歴配列をオブジェクトに変換
 * @param {Array} historiesArray - 履歴配列
 * @returns {Object} 履歴オブジェクト（キーはvideo ID）
 */
export const arrayToHistories = (historiesArray) => {
  return Object.fromEntries(
    historiesArray.map(item => [item.id, item])
  );
};

// ========================
// 統計
// ========================

/**
 * 統計情報を取得
 * @param {Object} histories - 履歴オブジェクト
 * @returns {Object} 統計情報
 */
export const getStatistics = (histories) => {
  const entries = Object.values(histories);
  const channelIds = new Set(entries.map(h => h.channel_id));

  return {
    totalVideos: entries.length,
    totalChannels: channelIds.size,
    oldestDate: entries.length > 0
      ? new Date(Math.min(...entries.map(h => new Date(h.download_date))))
      : null,
    newestDate: entries.length > 0
      ? new Date(Math.max(...entries.map(h => new Date(h.download_date))))
      : null,
  };
};
