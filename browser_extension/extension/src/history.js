/**
 * @module history
 * @description 履歴ページのメインロジック
 */

import {
  getDownloadHistories,
  deleteDownloadHistory,
  deleteMultipleHistories,
  updateDownloadHistory,
  deserializeImage,
  getJstDate,
  notify
} from './common.js';

import {
  ViewMode,
  SortType,
  sortHistories,
  filterByKeyword,
  groupAllByChannelId,
  getStatistics
} from './history-utils.js';

/**
 * 履歴管理クラス
 */
class HistoryManager {
  constructor() {
    this.histories = {};
    this.currentView = ViewMode.GRID;
    this.currentSort = SortType.DATE_DESC;
    this.searchKeyword = '';
    this.deleteTarget = null;
  }

  /**
   * 初期化
   */
  async init() {
    try {
      document.getElementById('loadingState').classList.remove('hide');

      this.histories = await getDownloadHistories();
      this.setupEventListeners();
      this.updateStatistics();
      await this.render();

      document.getElementById('loadingState').classList.add('hide');

      // 履歴が空の場合は空の状態を表示
      if (Object.keys(this.histories).length === 0) {
        document.getElementById('emptyState').classList.remove('hide');
      }
    } catch (error) {
      console.error('Initialization error:', error);
      await notify('Failed to load history', 'Error');
      document.getElementById('loadingState').classList.add('hide');
    }
  }

  /**
   * イベントリスナーの設定
   */
  setupEventListeners() {
    // 表示モード切替
    document.getElementById('gridViewBtn').addEventListener('click', () =>
      this.changeView(ViewMode.GRID)
    );
    document.getElementById('listViewBtn').addEventListener('click', () =>
      this.changeView(ViewMode.LIST)
    );
    document.getElementById('channelGroupViewBtn').addEventListener('click', () =>
      this.changeView(ViewMode.CHANNEL_GROUP)
    );

    // ソート
    document.getElementById('sortSelect').addEventListener('change', (e) =>
      this.changeSort(e.target.value)
    );

    // 検索
    let searchTimeout;
    document.getElementById('searchInput').addEventListener('input', (e) => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        this.search(e.target.value);
      }, 300); // デバウンス
    });

    // インポート/エクスポート
    document.getElementById('exportBtn').addEventListener('click', () =>
      this.exportHistories()
    );
    document.getElementById('importBtn').addEventListener('click', () =>
      document.getElementById('importInput').click()
    );
    document.getElementById('importInput').addEventListener('change', (e) =>
      this.importHistories(e)
    );

    // 削除確認ダイアログ
    document.getElementById('cancelDeleteBtn').addEventListener('click', () =>
      this.closeDeleteDialog()
    );
    document.getElementById('confirmDeleteBtn').addEventListener('click', () =>
      this.confirmDelete()
    );
  }

  /**
   * 統計情報を更新
   */
  updateStatistics() {
    const stats = getStatistics(this.histories);
    document.getElementById('totalVideos').textContent =
      `${stats.totalVideos} video${stats.totalVideos !== 1 ? 's' : ''}`;
    document.getElementById('totalChannels').textContent =
      `${stats.totalChannels} channel${stats.totalChannels !== 1 ? 's' : ''}`;
  }

  /**
   * 表示モードを変更
   */
  async changeView(viewMode) {
    this.currentView = viewMode;

    // ボタンのアクティブ状態を更新
    document.querySelectorAll('.view-btn').forEach(btn =>
      btn.classList.remove('active')
    );

    if (viewMode === ViewMode.GRID) {
      document.getElementById('gridViewBtn').classList.add('active');
    } else if (viewMode === ViewMode.LIST) {
      document.getElementById('listViewBtn').classList.add('active');
    } else if (viewMode === ViewMode.CHANNEL_GROUP) {
      document.getElementById('channelGroupViewBtn').classList.add('active');
    }

    await this.render();
  }

  /**
   * ソートを変更
   */
  async changeSort(sortType) {
    this.currentSort = sortType;
    await this.render();
  }

  /**
   * 検索
   */
  async search(keyword) {
    this.searchKeyword = keyword;
    await this.render();
  }

  /**
   * フィルタリングとソートを適用した履歴を取得
   */
  getProcessedHistories() {
    let processed = { ...this.histories };

    // フィルタリング
    if (this.searchKeyword) {
      processed = filterByKeyword(processed, this.searchKeyword);
    }

    // ソート
    processed = sortHistories(processed, this.currentSort);

    return processed;
  }

  /**
   * レンダリング
   */
  async render() {
    const container = document.getElementById('historyContainer');
    const processed = this.getProcessedHistories();

    container.innerHTML = '';
    container.className = this.currentView + '-view';

    // 結果が空の場合
    if (Object.keys(processed).length === 0) {
      document.getElementById('emptyState').classList.remove('hide');
      return;
    } else {
      document.getElementById('emptyState').classList.add('hide');
    }

    // 表示モードに応じてレンダリング
    switch (this.currentView) {
      case ViewMode.GRID:
        await this.renderGridView(processed, container);
        break;
      case ViewMode.LIST:
        await this.renderListView(processed, container);
        break;
      case ViewMode.CHANNEL_GROUP:
        await this.renderChannelGroupView(processed, container);
        break;
    }
  }

  /**
   * グリッドビューをレンダリング
   */
  async renderGridView(histories, container) {
    for (const [videoId, history] of Object.entries(histories)) {
      const card = await this.createVideoCard(history);
      container.appendChild(card);
    }
  }

  /**
   * リストビューをレンダリング
   */
  async renderListView(histories, container) {
    for (const [videoId, history] of Object.entries(histories)) {
      const item = await this.createVideoItem(history);
      container.appendChild(item);
    }
  }

  /**
   * チャンネルグループビューをレンダリング
   */
  async renderChannelGroupView(histories, container) {
    const grouped = groupAllByChannelId(histories);

    for (const [channelId, videos] of Object.entries(grouped)) {
      const group = await this.createChannelGroup(channelId, videos);
      container.appendChild(group);
    }
  }

  /**
   * 動画カード（グリッド用）を作成
   */
  async createVideoCard(history) {
    const card = document.createElement('div');
    card.className = 'video-card';

    const thumbnail = await deserializeImage(history.thumbnail);
    const channelAvatar = await deserializeImage(history.channel_avatar);

    card.innerHTML = `
      <img class="video-card-thumbnail" src="${thumbnail}" alt="${history.title}">
      <div class="video-card-content">
        <div class="video-card-title">${this.escapeHtml(history.title)}</div>
        <div class="video-card-channel">
          <img class="channel-avatar" src="${channelAvatar}" alt="${history.channel_name}">
          <span class="channel-name">${this.escapeHtml(history.channel_name)}</span>
        </div>
        <div class="video-card-date">${getJstDate(history.download_date)}</div>
        <div class="video-card-actions">
          <button class="btn-small btn-primary" data-action="open" data-id="${history.id}">Open</button>
          <button class="btn-small btn-secondary" data-action="update" data-id="${history.id}">Update</button>
          <button class="btn-small btn-danger" data-action="delete" data-id="${history.id}">Delete</button>
        </div>
      </div>
    `;

    // イベントリスナーを追加
    this.attachCardEventListeners(card, history);

    return card;
  }

  /**
   * 動画アイテム（リスト用）を作成
   */
  async createVideoItem(history) {
    const item = document.createElement('div');
    item.className = 'video-item';

    const thumbnail = await deserializeImage(history.thumbnail);
    const channelAvatar = await deserializeImage(history.channel_avatar);

    item.innerHTML = `
      <img class="video-item-thumbnail" src="${thumbnail}" alt="${history.title}">
      <div class="video-item-content">
        <div>
          <div class="video-item-title">${this.escapeHtml(history.title)}</div>
          <div class="video-item-info">
            <div class="video-item-channel">
              <img class="channel-avatar" src="${channelAvatar}" alt="${history.channel_name}">
              <span class="channel-name">${this.escapeHtml(history.channel_name)}</span>
            </div>
            <div>Downloaded: ${getJstDate(history.download_date)}</div>
          </div>
        </div>
        <div class="video-item-actions">
          <button class="btn-small btn-primary" data-action="open" data-id="${history.id}">Open Video</button>
          <button class="btn-small btn-secondary" data-action="update" data-id="${history.id}">Update Metadata</button>
          <button class="btn-small btn-danger" data-action="delete" data-id="${history.id}">Delete</button>
        </div>
      </div>
    `;

    // イベントリスナーを追加
    this.attachCardEventListeners(item, history);

    return item;
  }

  /**
   * チャンネルグループを作成
   */
  async createChannelGroup(channelId, videos) {
    const group = document.createElement('div');
    group.className = 'channel-group';

    // 最初の動画からチャンネル情報を取得
    const firstVideo = videos[0];
    const channelAvatar = await deserializeImage(firstVideo.channel_avatar);

    const header = document.createElement('div');
    header.className = 'channel-group-header';
    header.innerHTML = `
      <img class="channel-group-avatar" src="${channelAvatar}" alt="${firstVideo.channel_name}">
      <div class="channel-group-info">
        <div class="channel-group-name">${this.escapeHtml(firstVideo.channel_name)}</div>
        <div class="channel-group-count">${videos.length} video${videos.length !== 1 ? 's' : ''}</div>
      </div>
    `;

    const videosContainer = document.createElement('div');
    videosContainer.className = 'channel-group-videos';

    // 各動画のカードを作成
    for (const video of videos) {
      const card = await this.createVideoCard(video);
      videosContainer.appendChild(card);
    }

    group.appendChild(header);
    group.appendChild(videosContainer);

    return group;
  }

  /**
   * カードイベントリスナーを追加
   */
  attachCardEventListeners(element, history) {
    // 動画を開く
    element.querySelector('[data-action="open"]')?.addEventListener('click', (e) => {
      e.stopPropagation();
      window.open(`https://www.youtube.com/watch?v=${history.id}`, '_blank');
    });

    // メタデータを更新
    element.querySelector('[data-action="update"]')?.addEventListener('click', async (e) => {
      e.stopPropagation();
      await this.updateMetadata(history.id);
    });

    // 削除
    element.querySelector('[data-action="delete"]')?.addEventListener('click', (e) => {
      e.stopPropagation();
      this.showDeleteDialog(history.id, history.title);
    });
  }

  /**
   * 削除ダイアログを表示
   */
  showDeleteDialog(videoId, videoTitle) {
    this.deleteTarget = videoId;
    document.getElementById('deleteMessage').textContent =
      `Are you sure you want to delete "${videoTitle}"?`;
    document.getElementById('deleteDialog').classList.remove('hide');
  }

  /**
   * 削除ダイアログを閉じる
   */
  closeDeleteDialog() {
    this.deleteTarget = null;
    document.getElementById('deleteDialog').classList.add('hide');
  }

  /**
   * 削除を確認
   */
  async confirmDelete() {
    if (!this.deleteTarget) return;

    try {
      await deleteDownloadHistory(this.deleteTarget);
      delete this.histories[this.deleteTarget];

      this.updateStatistics();
      await this.render();
      await notify('Video deleted successfully');

      this.closeDeleteDialog();
    } catch (error) {
      console.error('Delete error:', error);
      await notify('Failed to delete video', 'Error');
    }
  }

  /**
   * メタデータを更新
   */
  async updateMetadata(videoId) {
    try {
      // YouTube動画ページを開いてユーザーに情報を取得させる
      const tabs = await chrome.tabs.query({ url: `*://www.youtube.com/watch?v=${videoId}*` });

      if (tabs.length > 0) {
        // すでにタブが開いている場合
        await chrome.tabs.update(tabs[0].id, { active: true });
        await this.requestPageInfo(tabs[0].id, videoId);
      } else {
        // 新しいタブで開く
        const tab = await chrome.tabs.create({
          url: `https://www.youtube.com/watch?v=${videoId}`,
          active: false
        });

        // ページが読み込まれるまで待つ
        await new Promise(resolve => {
          const listener = (tabId, changeInfo) => {
            if (tabId === tab.id && changeInfo.status === 'complete') {
              chrome.tabs.onUpdated.removeListener(listener);
              resolve();
            }
          };
          chrome.tabs.onUpdated.addListener(listener);
        });

        await this.requestPageInfo(tab.id, videoId);
        await chrome.tabs.remove(tab.id);
      }
    } catch (error) {
      console.error('Update metadata error:', error);
      await notify('Failed to update metadata', 'Error');
    }
  }

  /**
   * ページ情報をリクエスト
   */
  async requestPageInfo(tabId, videoId) {
    return new Promise((resolve, reject) => {
      chrome.tabs.sendMessage(tabId, { action: 'getPageInfo' }, async (response) => {
        if (!response) {
          reject(new Error('Failed to get page info'));
          return;
        }

        try {
          // 画像を再取得してメタデータを更新
          const { serializeImage } = await import('./common.js');
          const thumbnail = await serializeImage(response.thumbnail);
          const channelAvatar = await serializeImage(response.channelAvatar);

          const updatedData = {
            thumbnail: thumbnail,
            title: response.title,
            channel_name: response.channelName,
            channel_avatar: channelAvatar
          };

          await updateDownloadHistory(videoId, updatedData);

          // ローカルの履歴も更新
          this.histories[videoId] = { ...this.histories[videoId], ...updatedData };

          await this.render();
          await notify('Metadata updated successfully');
          resolve();
        } catch (error) {
          reject(error);
        }
      });
    });
  }

  /**
   * 履歴をエクスポート
   */
  async exportHistories() {
    try {
      const dataStr = JSON.stringify(this.histories, null, 2);
      const blob = new Blob([dataStr], { type: 'application/json' });
      const url = URL.createObjectURL(blob);

      const a = document.createElement('a');
      a.href = url;
      a.download = `youtube-download-history-${new Date().toISOString().split('T')[0]}.json`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      await notify('History exported successfully');
    } catch (error) {
      console.error('Export error:', error);
      await notify('Failed to export history', 'Error');
    }
  }

  /**
   * 履歴をインポート
   */
  async importHistories(event) {
    const file = event.target.files[0];
    if (!file) return;

    try {
      const text = await file.text();
      const importedData = JSON.parse(text);

      // データの検証
      if (typeof importedData !== 'object' || !importedData) {
        throw new Error('Invalid file format');
      }

      // 既存データとマージ（重複は上書き確認）
      let mergeCount = 0;
      let newCount = 0;

      for (const [videoId, data] of Object.entries(importedData)) {
        if (this.histories[videoId]) {
          mergeCount++;
        } else {
          newCount++;
        }
        this.histories[videoId] = data;
      }

      // ストレージに保存
      await chrome.storage.local.set({ downloadHistories: this.histories });

      this.updateStatistics();
      await this.render();
      await notify(`Imported: ${newCount} new, ${mergeCount} updated`);

      // ファイル入力をリセット
      event.target.value = '';
    } catch (error) {
      console.error('Import error:', error);
      await notify('Failed to import history', 'Error');
      event.target.value = '';
    }
  }

  /**
   * HTMLエスケープ
   */
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}

// 初期化
document.addEventListener('DOMContentLoaded', () => {
  const manager = new HistoryManager();
  manager.init();
});
