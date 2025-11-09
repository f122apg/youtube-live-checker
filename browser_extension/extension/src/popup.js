document.addEventListener('DOMContentLoaded', async () => {
  await loadHistories();

  chrome.tabs.query({active: true, currentWindow: true}, function(tabs) {
    // Content ScriptsからIDとタイトルを取得する
    chrome.tabs.sendMessage(tabs[0].id, {action: 'getPageInfo'}, async function(response) {
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

      let storage = await chrome.storage.local.get('downloadHistories');
      if (Object.keys(storage).length === 0) {
        return;
      }

      if (Object.keys(storage.downloadHistories).includes(videoId)) {
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
    }, response => {});
  });
});

const setThumbnail = async (thumbnailUrl, onErrorThumbnailUrl) => {
  const request = await fetch(thumbnailUrl);
  let url = thumbnailUrl;

  // サムネイルが404だったら、代替サムネイルを取得
  if (!request.ok) {
    url = onErrorThumbnailUrl;
  }

  document.getElementById('thumbnail').setAttribute('src', url);
}

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
    const imageSrc = await getImageUrl(history.thumbnail);
    const channelAvatarSrc = await getImageUrl(history.channel_avatar);
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
}

const getDownloadHistory = async () => {
  const storage = await chrome.storage.local.get('downloadHistories');
  if (Object.keys(storage).length === 0) {
    return {};
  }

  const downloadHistories = storage.downloadHistories;
  const sortedStorage = sortObjectByDate(downloadHistories);

  const storageGroupByChannelId = groupingByChannelId(sortedStorage);

  return storageGroupByChannelId;
}

const groupingByChannelId = obj => {
  const storageGroup = {};

  for (const [key, data] of Object.entries(obj)) {
    storageGroup[data.channel_id] = data;
  };

  return storageGroup;
}

const sortObjectByDate = obj => {
  return Object.fromEntries(
      Object.entries(obj).sort(([, a], [, b]) => {
          return new Date(b.download_date) - new Date(a.download_date);
      })
  );
}

const getJstDate = dateIso => {
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
}

const getImageUrl = async (base64) => {
  let blobUrl = '';

  try {
    const gzipBin = base64ToArrayBuffer(base64);
    const data = await ungzip(gzipBin);

    const blob = new Blob([data], { type: 'image/jpeg' });
    blobUrl = URL.createObjectURL(blob);
  } catch (e) {
    console.error(e);
  }

  return blobUrl;
}

const base64ToArrayBuffer = (base64) => {
  // Base64文字列をバイナリ文字列にデコード
  const binaryString = window.atob(base64);

  // バイナリ文字列をUint8Arrayに変換
  const len = binaryString.length;
  const bytes = new Uint8Array(len);
  for (let i = 0; i < len; i++) {
      bytes[i] = binaryString.charCodeAt(i);
  }

  // Uint8ArrayをArrayBufferに変換
  return bytes.buffer;
}

const ungzip = async data => {
	const readableStream = new Blob([data]).stream();
	const decompressedStream = readableStream.pipeThrough(
        // メモ: 毎回インスタンス化する必要がある
        new DecompressionStream('gzip'),
    );
	const arrayBuffer = await new Response(decompressedStream).arrayBuffer();
	return arrayBuffer;
};