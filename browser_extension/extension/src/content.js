chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === "getPageInfo") {
    // const videoThumbnail = document.querySelector('meta[property="og:image"]').getAttribute('content');
    const videoId = (new URLSearchParams(window.location.search)).get('v');
    const videoTitle = document.querySelector('#title h1 yt-formatted-string').textContent;
    const videoThumbnail = 'https://i.ytimg.com/vi/' + videoId + '/maxresdefault.jpg';

    sendResponse({
      thumbnail: videoThumbnail,
      id: videoId,
      title: videoTitle
    });
  }
});