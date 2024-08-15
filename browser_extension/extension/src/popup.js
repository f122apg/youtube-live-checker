document.addEventListener('DOMContentLoaded', function() {
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

      document.getElementById('thumbnail').setAttribute('src', videoThumbnail);
      document.getElementById('id').textContent = videoId;
      document.getElementById('title').textContent = videoTitle;


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

    document.getElementById('downloadButton').setAttribute('disabled', 'true');

    // Background Scripts経由でネイティブアプリを起動してダウンロードを開始する
    chrome.runtime.sendMessage({
      action: 'sendNativeMessage',
      data: {
        videoThumbnailUrl: thumbnailUrl,
        videoId: id,
        videoTitle: title
      }
    }, response => {});
  });
});