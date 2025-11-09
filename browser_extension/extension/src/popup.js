import {
  getDownloadHistories,
  deserializeImage,
  getJstDate
} from './common.js';

import {
  groupByChannelId,
  sortByDate
} from './history-utils.js';

document.addEventListener('DOMContentLoaded', async () => {
  await loadHistories();

  // 履歴ページを開くボタンのイベントリスナー
  document.getElementById('openHistoryButton')?.addEventListener('click', () => {
    chrome.tabs.create({ url: chrome.runtime.getURL('history.html') });
  });

  chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
    // Content ScriptsからIDとタイトルを取得する
    chrome.tabs.sendMessage(tabs[0].id, { action: 'getPageInfo' }, async function (response) {
      if (!response) {
        document.querySelector('#video-area').classList.add('hide');
        document.querySelector('#error-area').classList.remove('hide');
        return;
      }

      const videoThumbnail = response.thumbnail;
      const videoId = response.id;
      const videoTitle = response.title;

      await setThumbnail(videoThumbnail, response.onErrorThumbnail);
      document.getElementById('id').textContent = videoId;
      document.getElementById('title').textContent = videoTitle;

      document.getElementById('channel_id').textContent = response.channelId;
      document.getElementById('channel_avatar').setAttribute('src', response.channelAvatar);
      document.getElementById('channel_name').textContent = response.channelName;

      const histories = await getDownloadHistories();

      if (Object.keys(histories).includes(videoId)) {
        document.getElementById('downloadButton').setAttribute('disabled', 'true');
        document.getElementById('downloadButton').textContent = 'Downloaded Video';
      }
    });
  });

  document.getElementById('downloadButton').addEventListener('click', async () => {
    const thumbnailUrl = document.getElementById('thumbnail').getAttribute('src');
    const id = document.getElementById('id').textContent;
    const title = document.getElementById('title').textContent;
    const channelId = document.getElementById('channel_id').textContent;
    const channelAvatar = document.getElementById('channel_avatar').getAttribute('src');
    const channelName = document.getElementById('channel_name').textContent;

    document.getElementById('downloadButton').setAttribute('disabled', 'true');

    // Background Scripts経由でネイティブアプリを起動してダウンロードを開始する
    chrome.runtime.sendMessage({
      action: 'sendNativeMessage',
      data: {
        videoThumbnailUrl: thumbnailUrl,
        videoId: id,
        videoTitle: title,
        channelId: channelId,
        channelAvatar: channelAvatar,
        channelName: channelName,
      }
    }, response => { });
  });
});

/**
 * サムネイル画像を設定
 * @param {string} thumbnailUrl - サムネイルURL
 * @param {string} onErrorThumbnailUrl - エラー時の代替サムネイルURL
 */
const setThumbnail = async (thumbnailUrl, onErrorThumbnailUrl) => {
  const request = await fetch(thumbnailUrl);
  let url = thumbnailUrl;

  // サムネイルが404だったら、代替サムネイルを取得
  if (!request.ok) {
    url = onErrorThumbnailUrl;
  }

  document.getElementById('thumbnail').setAttribute('src', url);
};

/**
 * ダウンロード履歴を読み込んで表示
 */
const loadHistories = async () => {
  const downloadHistories = await getDownloadHistory();
  if (Object.keys(downloadHistories).length === 0) {
    return;
  }

  const htmlTmpl = `
    <div class="title">
      <span>{title}</span>
    </div>
    <div>
      <a href="https://www.youtube.com/watch?v={id}">
        <img class="thumbnail" src="{src}"></img>
      </a>
    </div>
    <div class="channel_container">
      <a href="https://www.youtube.com/channel/{channel_id}">
        <img class="channel_avatar" src="{channel_avatar}"></img>
        <span class="channel_name">{channel_name}</span>
      </a>
    </div>
    <div>Download date: <span class="download_date">{download_date}</span></div>
  `;

  const historyArea = document.querySelector('#history-area');
  historyArea.classList.remove('hide');

  const historyEntries = Object.entries(downloadHistories).map(v => v[1]);
  for (const history of historyEntries) {
    const imageSrc = await deserializeImage(history.thumbnail);
    const channelAvatarSrc = await deserializeImage(history.channel_avatar);
    const html = htmlTmpl
      .replaceAll('{src}', imageSrc)
      .replaceAll('{id}', history.id)
      .replaceAll('{title}', history.title)
      .replaceAll('{download_date}', getJstDate(history.download_date))
      .replaceAll('{channel_id}', history.channel_id)
      .replaceAll('{channel_name}', history.channel_name)
      .replaceAll('{channel_avatar}', channelAvatarSrc);

    const container = document.createElement('div');
    container.classList.add('container');
    container.innerHTML = html;
    historyArea.insertAdjacentElement('beforeend', container);
  }
};

/**
 * ダウンロード履歴を取得（ソート＋グルーピング済み）
 */
const getDownloadHistory = async () => {
  const histories = await getDownloadHistories();
  if (Object.keys(histories).length === 0) {
    return {};
  }

  const sortedHistories = sortByDate(histories, true);
  const groupedHistories = groupByChannelId(sortedHistories);

  return groupedHistories;
};
