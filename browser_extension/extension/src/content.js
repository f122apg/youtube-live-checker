chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === "getPageInfo") {
    // const videoThumbnail = document.querySelector('meta[property="og:image"]').getAttribute('content');
    const videoId = (new URLSearchParams(window.location.search)).get('v');
    const videoTitle = document.querySelector('#title h1 yt-formatted-string').textContent;
    const videoThumbnail = 'https://i.ytimg.com/vi/' + videoId + '/maxresdefault.jpg';

    const channelName = document.querySelector('ytd-channel-name.ytd-video-owner-renderer a.yt-simple-endpoint').textContent;
    const channelAvatar = document.querySelector('ytd-video-owner-renderer #img').src;
    const channelUrl = document.querySelector('ytd-channel-name.ytd-video-owner-renderer a.yt-simple-endpoint').href;
    const channelId = getChannelId(channelUrl);

    sendResponse({
      thumbnail: videoThumbnail,
      id: videoId,
      title: videoTitle,
      channelId: channelId,
      channelName: channelName,
      channelAvatar: channelAvatar,
    });
  }
});

const getChannelId = channelUrl => {
  return channelUrl.split('/').pop();
}