/**
 * @module common
 * @description 拡張機能全体で使用する共通ユーティリティ
 */

// ========================
// ストレージ操作
// ========================

/**
 * ダウンロード履歴を取得
 * @returns {Promise<Object>} ダウンロード履歴オブジェクト
 */
export const getDownloadHistories = async () => {
  const storage = await chrome.storage.local.get('downloadHistories');
  return storage.downloadHistories ?? {};
};

/**
 * ダウンロード履歴を保存
 * @param {Object} data - 保存するデータ
 * @param {string} data.thumbnailUrl - サムネイルURL
 * @param {string} data.videoId - 動画ID
 * @param {string} data.videoTitle - 動画タイトル
 * @param {string} data.channelId - チャンネルID
 * @param {string} data.channelName - チャンネル名
 * @param {string} data.channelAvatar - チャンネルアバターURL
 * @returns {Promise<void>}
 */
export const saveDownloadHistory = async (data) => {
  const histories = await getDownloadHistories();

  const thumbnail = await serializeImage(data.thumbnailUrl);
  const channelId = await getChannelId(data.channelId);
  const channelAvatar = await serializeImage(data.channelAvatar);

  const historyData = {
    thumbnail: thumbnail,
    id: data.videoId,
    title: data.videoTitle,
    channel_id: channelId,
    channel_name: data.channelName,
    channel_avatar: channelAvatar,
    download_date: (new Date()).toISOString()
  };

  histories[data.videoId] = historyData;
  await chrome.storage.local.set({ downloadHistories: histories });
};

/**
 * ダウンロード履歴を削除
 * @param {string} videoId - 削除する動画ID
 * @returns {Promise<void>}
 */
export const deleteDownloadHistory = async (videoId) => {
  const histories = await getDownloadHistories();
  delete histories[videoId];
  await chrome.storage.local.set({ downloadHistories: histories });
};

/**
 * 複数のダウンロード履歴を削除
 * @param {string[]} videoIds - 削除する動画IDの配列
 * @returns {Promise<void>}
 */
export const deleteMultipleHistories = async (videoIds) => {
  const histories = await getDownloadHistories();
  videoIds.forEach(id => delete histories[id]);
  await chrome.storage.local.set({ downloadHistories: histories });
};

/**
 * ダウンロード履歴を更新
 * @param {string} videoId - 更新する動画ID
 * @param {Object} newData - 新しいデータ
 * @returns {Promise<void>}
 */
export const updateDownloadHistory = async (videoId, newData) => {
  const histories = await getDownloadHistories();
  if (histories[videoId]) {
    histories[videoId] = { ...histories[videoId], ...newData };
    await chrome.storage.local.set({ downloadHistories: histories });
  }
};

// ========================
// GCP設定操作
// ========================

/**
 * GCP Workflow Execution APIのURLを取得
 * @returns {Promise<string>} API URL
 * @throws {Error} GCP設定が見つからない場合
 */
export const getWorkflowExecutionApiUrl = async () => {
  const tmpl = 'https://workflowexecutions.googleapis.com/v1/projects/{project_id}/locations/{location}/workflows/{workflow_name}/executions';

  const storage = await chrome.storage.local.get('gcpSettings');
  if (Object.keys(storage).length === 0) {
    throw new Error('Please input gcp settings.');
  }

  const apiUrl = tmpl
    .replace('{project_id}', storage.gcpSettings.project_id)
    .replace('{location}', storage.gcpSettings.location)
    .replace('{workflow_name}', storage.gcpSettings.workflow_name);

  return apiUrl;
};

// ========================
// 画像処理
// ========================

/**
 * 画像URLを取得してgzip圧縮しbase64エンコード
 * @param {string} imageUrl - 画像URL
 * @returns {Promise<string>} base64エンコードされた圧縮画像
 * @throws {Error} 画像取得に失敗した場合
 */
export const serializeImage = async (imageUrl) => {
  try {
    const response = await fetch(imageUrl);

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const blob = await response.blob();
    const gzipBuffer = await gzip(blob);
    const base64String = arrayBufferToBase64(gzipBuffer);

    return base64String;
  } catch (error) {
    console.error('Error fetching or converting image:', error);
    throw error;
  }
};

/**
 * base64エンコードされた圧縮画像をBlobURLに変換
 * @param {string} base64 - base64文字列
 * @returns {Promise<string>} Blob URL
 */
export const deserializeImage = async (base64) => {
  try {
    const gzipBin = base64ToArrayBuffer(base64);
    const data = await ungzip(gzipBin);

    const blob = new Blob([data], { type: 'image/jpeg' });
    return URL.createObjectURL(blob);
  } catch (error) {
    console.error('Error deserializing image:', error);
    return '';
  }
};

/**
 * Blobをgzip圧縮
 * @param {Blob} blob - 圧縮するBlob
 * @returns {Promise<ArrayBuffer>} 圧縮されたArrayBuffer
 */
export const gzip = async (blob) => {
  const readableStream = blob.stream();
  const compressedStream = readableStream.pipeThrough(
    new CompressionStream('gzip'),
  );
  const arrayBuffer = await new Response(compressedStream).arrayBuffer();
  return arrayBuffer;
};

/**
 * gzip圧縮されたデータを解凍
 * @param {ArrayBuffer} data - 圧縮されたデータ
 * @returns {Promise<ArrayBuffer>} 解凍されたArrayBuffer
 */
export const ungzip = async (data) => {
  const readableStream = new Blob([data]).stream();
  const decompressedStream = readableStream.pipeThrough(
    new DecompressionStream('gzip'),
  );
  const arrayBuffer = await new Response(decompressedStream).arrayBuffer();
  return arrayBuffer;
};

/**
 * ArrayBufferをbase64文字列に変換
 * @param {ArrayBuffer} buffer - 変換するArrayBuffer
 * @returns {string} base64文字列
 */
export const arrayBufferToBase64 = (buffer) => {
  let binary = '';
  const bytes = new Uint8Array(buffer);
  const len = bytes.byteLength;
  for (let i = 0; i < len; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return window.btoa(binary);
};

/**
 * base64文字列をArrayBufferに変換
 * @param {string} base64 - base64文字列
 * @returns {ArrayBuffer} ArrayBuffer
 */
export const base64ToArrayBuffer = (base64) => {
  const binaryString = window.atob(base64);
  const len = binaryString.length;
  const bytes = new Uint8Array(len);
  for (let i = 0; i < len; i++) {
    bytes[i] = binaryString.charCodeAt(i);
  }
  return bytes.buffer;
};

// ========================
// ユーティリティ
// ========================

/**
 * チャンネルIDを取得（@username形式の場合はHTMLから抽出）
 * @param {string} channelIdOrUrl - チャンネルIDまたは@usernameを含むURL
 * @returns {Promise<string>} チャンネルID
 */
export const getChannelId = async (channelIdOrUrl) => {
  // すでにチャンネルIDの場合はそのまま返す
  if (!channelIdOrUrl.includes('@')) {
    return channelIdOrUrl;
  }

  try {
    const startWithChannelId = '<meta itemprop="identifier" content="';

    const request = await fetch('https://www.youtube.com/' + channelIdOrUrl);
    const response = await request.text();

    const startPos = response.indexOf(startWithChannelId);
    const endPos = response.indexOf('"', startPos + startWithChannelId.length);

    return response.substring(startPos + startWithChannelId.length, endPos);
  } catch (error) {
    console.error('Error fetching channel ID:', error);
    return channelIdOrUrl; // エラー時は元の値を返す
  }
};

/**
 * ISO日付文字列を日本時間の表示用文字列に変換
 * @param {string} dateIso - ISO形式の日付文字列
 * @returns {string} 表示用の日付文字列
 */
export const getJstDate = (dateIso) => {
  const jst = new Date(new Date(dateIso).toLocaleString({ timeZone: 'Asia/Tokyo' }));

  return jst.toLocaleDateString(
    'ja-JP',
    {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    }
  );
};

/**
 * 通知を表示
 * @param {string} message - 通知メッセージ
 * @param {string} [title] - 通知タイトル（省略時は拡張機能名）
 * @returns {Promise<void>}
 */
export const notify = async (message, title = null) => {
  const manifest = chrome.runtime.getManifest();

  chrome.notifications.create({
    type: 'basic',
    iconUrl: chrome.runtime.getURL('icon.svg'),
    title: title ?? manifest.name,
    message: message,
  });
};

/**
 * ytInitialPlayerResponseを抽出（プレイヤー情報）
 * @param {string} html - YouTubeページのHTML
 * @returns {Object|null} パース済みのJSONデータ、または null
 */
const extractYtInitialPlayerResponse = (html) => {
  const patterns = [
    /var ytInitialPlayerResponse = ({.+?});/s,
    /window\["ytInitialPlayerResponse"\] = ({.+?});/s,
    /ytInitialPlayerResponse = ({.+?});/s
  ];

  for (const pattern of patterns) {
    const match = html.match(pattern);
    if (match) {
      try {
        return JSON.parse(match[1]);
      } catch (e) {
        console.error('ytInitialPlayerResponse parse error:', e);
      }
    }
  }
  return null;
};

/**
 * ytInitialDataを抽出（メインのページデータ）
 * @param {string} html - YouTubeページのHTML
 * @returns {Object|null} パース済みのJSONデータ、または null
 */
const extractYtInitialData = (html) => {
  const patterns = [
    /var ytInitialData = ({.+?});/s,
    /window\["ytInitialData"\] = ({.+?});/s,
    /ytInitialData = ({.+?});/s
  ];

  for (const pattern of patterns) {
    const match = html.match(pattern);
    if (match) {
      try {
        return JSON.parse(match[1]);
      } catch (e) {
        console.error('ytInitialData parse error:', e);
      }
    }
  }
  return null;
};

/**
 * fetchで動画のメタデータを取得
 * @param {string} videoId - YouTube動画ID
 * @returns {Promise<Object>} { title, thumbnail, channelId, channelName, channelAvatar }
 */
export const fetchVideoMetadata = async (videoId) => {
  const url = `https://www.youtube.com/watch?v=${videoId}`;

  const response = await fetch(url, {
    headers: {
      'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    }
  });

  if (!response.ok) {
    throw new Error(`HTTP Error: ${response.status}`);
  }

  const html = await response.text();

  // ytInitialPlayerResponse JSONを抽出
  const data = extractYtInitialPlayerResponse(html);
  if (!data) {
    throw new Error('ytInitialPlayerResponse not found');
  }

  // 基本情報を抽出
  const title = data.videoDetails?.title || '';
  const channelId = data.videoDetails?.channelId || '';
  const channelName = data.microformat?.playerMicroformatRenderer?.ownerChannelName || '';

  // サムネイル
  const thumbnail = `https://i.ytimg.com/vi/${videoId}/maxresdefault.jpg`;

  // チャンネルアバターを抽出（ytInitialDataから取得）
  const initialData = extractYtInitialData(html);
  let channelAvatar = '';

  if (initialData) {
    try {
      // videoSecondaryInfoRenderer から取得
      const contents = initialData?.contents?.twoColumnWatchNextResults?.results?.results?.contents;
      if (contents) {
        for (const content of contents) {
          if (content.videoSecondaryInfoRenderer) {
            const thumbnails = content.videoSecondaryInfoRenderer
              ?.owner?.videoOwnerRenderer?.thumbnail?.thumbnails;

            if (thumbnails && thumbnails.length > 0) {
              // 一番画質が良いもの（配列の最後、または最大width）を取得
              const bestThumbnail = thumbnails.reduce((prev, current) =>
                (current.width > prev.width) ? current : prev
              );
              channelAvatar = bestThumbnail.url;
            }
            break;
          }
        }
      }
    } catch (parseError) {
      console.error('Failed to extract channel avatar:', parseError.message);
      // チャンネルアバターが取得できなくても続行
    }
  }

  return { title, thumbnail, channelId, channelName, channelAvatar };
};

/**
 * 動画をダウンロード（Native Message送信）
 * @param {Object} data - ダウンロードに必要なデータ
 * @param {string} data.videoThumbnailUrl - サムネイルURL
 * @param {string} data.videoId - 動画ID
 * @param {string} data.videoTitle - 動画タイトル
 * @param {string} data.channelId - チャンネルID
 * @param {string} data.channelName - チャンネル名
 * @param {string} data.channelAvatar - チャンネルアバターURL
 * @returns {Promise<void>}
 */
export const downloadVideo = async (data) => {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage({
      action: 'sendNativeMessage',
      data: data
    }, response => {
      if (chrome.runtime.lastError) {
        reject(chrome.runtime.lastError);
      } else {
        resolve(response);
      }
    });
  });
};
