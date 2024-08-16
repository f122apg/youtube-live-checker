document.addEventListener('DOMContentLoaded', () => {
  const getGcpSettings = chrome.storage.local.get('gcpSettings');
  getGcpSettings.then((data) => {
    if (Object.keys(data).length === 0) {
      return;
    }

    document.querySelector('#project_id').value = data.gcpSettings.project_id;
    document.querySelector('#location').value = data.gcpSettings.location;
    document.querySelector('#workflow_name').value = data.gcpSettings.workflow_name;
  });

  document.querySelector('#save').addEventListener('click', () => {
    const data = {
      project_id: document.querySelector('#project_id').value,
      location: document.querySelector('#location').value,
      workflow_name: document.querySelector('#workflow_name').value,
    }

    chrome.storage.local.set({'gcpSettings': data}, () => {});
  });

  document.querySelector('#delete').addEventListener('click', async () => {
    let storage = await chrome.storage.local.get('downloadHistories');
    if (Object.keys(storage).length === 0) {
      storage = {
        downloadHistories: {}
      };
    }

    const deleteId = document.querySelector('#delete_id').value;
    delete storage.downloadHistories[deleteId];

    chrome.storage.local.set(storage, () => {});
  });
});