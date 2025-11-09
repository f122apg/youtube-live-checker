import { deleteDownloadHistory, notify } from './common.js';

document.addEventListener('DOMContentLoaded', async () => {
  // GCP設定を読み込む
  const getGcpSettings = await chrome.storage.local.get('gcpSettings');
  if (Object.keys(getGcpSettings).length > 0) {
    document.querySelector('#project_id').value = getGcpSettings.gcpSettings.project_id;
    document.querySelector('#location').value = getGcpSettings.gcpSettings.location;
    document.querySelector('#workflow_name').value = getGcpSettings.gcpSettings.workflow_name;
  }

  // GCP設定を保存
  document.querySelector('#save').addEventListener('click', async () => {
    const data = {
      project_id: document.querySelector('#project_id').value,
      location: document.querySelector('#location').value,
      workflow_name: document.querySelector('#workflow_name').value,
    };

    await chrome.storage.local.set({ 'gcpSettings': data });
    await notify('Settings saved successfully');
  });

  // ダウンロード履歴を削除
  document.querySelector('#delete').addEventListener('click', async () => {
    const deleteId = document.querySelector('#delete_id').value;

    if (!deleteId) {
      await notify('Please enter a video ID', 'Error');
      return;
    }

    try {
      await deleteDownloadHistory(deleteId);
      await notify('History deleted successfully');
      document.querySelector('#delete_id').value = '';
    } catch (error) {
      console.error('Delete error:', error);
      await notify('Failed to delete history', 'Error');
    }
  });
});
