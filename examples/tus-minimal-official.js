/**
 * Minimal tus-js-client Implementation
 * Based on official tus.io documentation
 */

import * as tus from 'tus-js-client';

// Basic usage example from official docs
const input = document.getElementById('file-input');

input.addEventListener('change', function (e) {
  // Get the selected file from the input element
  const file = e.target.files[0];

  // Create a new tus upload
  const upload = new tus.Upload(file, {
    endpoint: 'http://localhost:1080/files/',
    retryDelays: [0, 3000, 5000, 10000, 20000],
    metadata: {
      filename: file.name,
      filetype: file.type,
    },
    onError: function (error) {
      console.log('Failed because: ' + error);
    },
    onProgress: function (bytesUploaded, bytesTotal) {
      const percentage = ((bytesUploaded / bytesTotal) * 100).toFixed(2);
      console.log(bytesUploaded, bytesTotal, percentage + '%');
    },
    onSuccess: function () {
      console.log('Download %s from %s', upload.file.name, upload.url);
    },
  });

  // Check if there are any previous uploads to continue.
  upload.findPreviousUploads().then(function (previousUploads) {
    // Found previous uploads so we select the first one.
    if (previousUploads.length) {
      upload.resumeFromPreviousUpload(previousUploads[0]);
    }

    // Start the upload
    upload.start();
  });
});

export { tus };