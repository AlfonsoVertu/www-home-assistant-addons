const fields = ['siteUrl','userCode','bearerToken','mimeType','fileName','contentType','contentId','seoTitle','featured','insertContent','gallery','insertPosition','h2Index'];
const logBox = document.getElementById('log');
const progressEvents = [];
const putLog = (v) => { logBox.textContent = JSON.stringify(v, null, 2); };

function addProgress(event) {
  progressEvents.push(event);
  putLog({ status: "uploading", progress: progressEvents });
}

async function saveSettings() {
  const payload = {};
  for (const id of fields) {
    const el = document.getElementById(id);
    payload[id] = el.type === 'checkbox' ? el.checked : el.value;
  }
  await chrome.storage.local.set({ uploaderSettings: payload });
}

async function loadSettings() {
  const { uploaderSettings } = await chrome.storage.local.get('uploaderSettings');
  if (!uploaderSettings) return;
  for (const id of fields) {
    const el = document.getElementById(id);
    if (!(id in uploaderSettings)) continue;
    if (el.type === 'checkbox') el.checked = !!uploaderSettings[id]; else el.value = uploaderSettings[id];
  }
}

function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',').pop() || '');
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

async function askHostPermission(siteUrl) {
  const origin = `${new URL(siteUrl).origin}/*`;
  const granted = await chrome.permissions.request({ origins: [origin] });
  if (!granted) throw new Error(`Permesso host negato: ${origin}`);
}

document.getElementById('uploadBtn').addEventListener('click', async () => {
  try {
    await saveSettings();
    const cfg = {};
    for (const id of fields) {
      const el = document.getElementById(id);
      cfg[id] = el.type === 'checkbox' ? el.checked : el.value;
    }

    if (!cfg.siteUrl || !cfg.userCode || !cfg.bearerToken) throw new Error('Compila site URL, user_code e bearer token.');

    const fileInput = document.getElementById('fileInput');
    const files = Array.from(fileInput.files || []);
    if (!files.length) throw new Error('Seleziona almeno un file immagine.');

    await askHostPermission(cfg.siteUrl);

    const preparedFiles = [];
    for (const file of files) {
      preparedFiles.push({
        originalName: file.name,
        filename: cfg.fileName || file.name,
        mimeType: cfg.mimeType || file.type || 'image/png',
        base64: await fileToBase64(file),
      });
    }

    const response = await chrome.runtime.sendMessage({ type: 'WWWW_UPLOAD_CHUNKED', settings: cfg, files: preparedFiles });
    if (!response?.ok) throw new Error(response?.error || 'Errore sconosciuto');
    putLog(response.log);
  } catch (e) {
    putLog({ ok: false, error: e.message });
  }
});

fields.forEach((id) => document.getElementById(id)?.addEventListener('change', saveSettings));
loadSettings();


chrome.runtime.onMessage.addListener((message) => {
  if (message?.type === "WWWW_UPLOAD_PROGRESS" && message.progress) {
    addProgress(message.progress);
  }
});