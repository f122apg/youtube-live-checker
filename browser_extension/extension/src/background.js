chrome.runtime.onMessage.addListener(async (message, sender, sendResponse) => {
  if (message.action === 'sendNativeMessage') {
    const token = await chrome.runtime.sendNativeMessage('net.f122apg.youtube_downloader', null);

    try {
      const workflowExecutionsApiUrl = await getWorkflowExecutionApiUrl();

      const data = {
        argument: JSON.stringify({
          contentId: message.data.videoId,
          title: message.data.videoTitle
        })
      };
      const options = {
        method: 'POST',
        headers: {'Content-Type': 'application/json', Authorization: 'Bearer ' + token},
        body: JSON.stringify(data)
      };

      const request = await fetch(workflowExecutionsApiUrl, options);
      if (!request.ok) {
        console.log(await request.statusText());
        console.log(await request.text());

        await notify('Failed Download.\r\n' + message.data.videoTitle);

        return;
      }

      await saveDownloadHistory({
        thumbnailUrl: message.data.videoThumbnailUrl,
        videoId: message.data.videoId,
        videoTitle: message.data.videoTitle,
        channelId: message.data.channelId,
        channelName: message.data.channelName,
        channelAvatar: message.data.channelAvatar,
      });

      await notify('Success Download.\r\n' + message.data.videoTitle);
    } catch (error) {
      console.log(error);
      await notify('Failed Download.\r\n' + message.data.videoTitle + '\r\n' + error);
    }
  }
});

const saveDownloadHistory = async (params) => {
  let storage = await chrome.storage.local.get('downloadHistories');
  if (Object.keys(storage).length === 0) {
    storage = {
      downloadHistories: {}
    };
  }

  const thumbnail = await serializeImage(params.thumbnailUrl);
  const channelId = await getChannelId(params.channelId);
  const channelAvatar = await serializeImage(params.channelAvatar);
  const data = {
    thumbnail: thumbnail,
    id: params.videoId,
    title: params.videoTitle,
    channel_id: channelId,
    channel_name: params.channelName,
    channel_avatar: channelAvatar,
    download_date: (new Date()).toISOString()
  }

  storage.downloadHistories[params.videoId] = data;
  chrome.storage.local.set(storage, () => {});
}

const serializeImage = async (imageUrl) => {
  try {
      // fetchを使って画像を取得する
      const response = await fetch(imageUrl);

      // レスポンスがokでない場合はエラーをthrowする
      if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
      }

      // レスポンスをBlobに変換
      const blob = await response.blob();
      // そのままbase64にするとサイズが大きいのでgzip圧縮
      const gzipBuffer = await gzip(blob);
      const base64String = arrayBufferToBase64(gzipBuffer);

      return base64String;
  } catch (error) {
      console.error('Error fetching or converting image:', error);
      throw error;
  }
}

const gzip = async blob => {
	const readableStream = blob.stream();
	const compressedStream = readableStream.pipeThrough(
        new CompressionStream('gzip'),
    );
	const arrayBuffer = await new Response(compressedStream).arrayBuffer();
	return arrayBuffer;
};

const arrayBufferToBase64 = buffer => {
  let binary = '';
  const bytes = new Uint8Array(buffer);
  const len = bytes.byteLength;
  for (var i = 0; i < len; i++) {
      binary += String.fromCharCode(bytes[i]);
  }

  return window.btoa(binary);
}

const getChannelId = async channelId => {
  if (!channelId.includes('@')) {
    return channelId;
  }

  const startWithChannelId = '<meta itemprop="identifier" content="';

  const request = await fetch('https://www.youtube.com/' + channelId);
  const response = await request.text();

  const startPos = response.indexOf(startWithChannelId);
  const endPos = response.indexOf('"', startPos + startWithChannelId.length);

  return response.substring(startPos + startWithChannelId.length, endPos);
}

const getWorkflowExecutionApiUrl = async () => {
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
}

const notify = async (content) => {
  const manifest = await chrome.runtime.getManifest();

  chrome.notifications.create({
    type: 'basic',
    iconUrl: chrome.runtime.getURL('icon.svg'),
    title: manifest.name,
    message: content,
  });
}