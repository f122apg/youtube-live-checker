import {
  saveDownloadHistory,
  getWorkflowExecutionApiUrl,
  notify
} from './common.js';

chrome.runtime.onMessage.addListener(async (message, sender, sendResponse) => {
  if (message.action === 'sendNativeMessage') {
    try {
      // ネイティブアプリからGCPトークンを取得
      const token = await chrome.runtime.sendNativeMessage('net.f122apg.youtube_downloader', null);

      // GCP Workflow APIのURLを取得
      const workflowExecutionsApiUrl = await getWorkflowExecutionApiUrl();

      // ワークフローを実行
      const data = {
        argument: JSON.stringify({
          contentId: message.data.videoId,
          title: message.data.videoTitle
        })
      };

      const options = {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: 'Bearer ' + token
        },
        body: JSON.stringify(data)
      };

      const request = await fetch(workflowExecutionsApiUrl, options);

      if (!request.ok) {
        const statusText = request.statusText;
        const errorText = await request.text();
        console.error('Workflow execution failed:', statusText, errorText);

        await notify('Failed Download.\r\n' + message.data.videoTitle);
        return;
      }

      // ダウンロード履歴を保存
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
      console.error('Download error:', error);
      await notify('Failed Download.\r\n' + message.data.videoTitle + '\r\n' + error.message);
    }
  }
});
