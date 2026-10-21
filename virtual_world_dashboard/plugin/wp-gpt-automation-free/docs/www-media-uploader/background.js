const DEFAULT_CHUNK_SIZE = 256 * 1024;
const MIN_CHUNK_SIZE = 128 * 1024;
const MAX_CHUNK_SIZE = 768 * 1024;
const MAX_CHUNKS = 60;
const norm = (s) => String(s || '').replace(/\/$/, '');

function getChunkPlan(base64Length) {
  if (base64Length <= DEFAULT_CHUNK_SIZE) {
    return { chunkSize: base64Length || DEFAULT_CHUNK_SIZE, totalChunks: base64Length ? 1 : 0 };
  }

  let chunkSize = Math.ceil(base64Length / MAX_CHUNKS);
  chunkSize = Math.max(MIN_CHUNK_SIZE, Math.min(MAX_CHUNK_SIZE, chunkSize));
  const totalChunks = Math.ceil(base64Length / chunkSize);
  return { chunkSize, totalChunks };
}

function splitChunks(base64, size) {
  const out = [];
  for (let i = 0; i < base64.length; i += size) out.push(base64.slice(i, i + size));
  return out;
}

async function callJson(url, token, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify(payload),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${res.status}: ${JSON.stringify(data)}`);
  return data;
}

const imgHtml = (url, alt='') => `<img src="${url}" alt="${alt.replace(/"/g, '&quot;')}" />`;

async function postOps(site, token, cfg, upload) {
  const log = [];
  const id = Number(cfg.contentId || 0);
  if (!id) return log;
  const att = upload.attachment_id;
  const url = upload.media_url;

  if (cfg.contentType === 'article') {
    if (cfg.featured) log.push(await callJson(`${site}/wp-json/wp-gpt/v1/update-post/${id}`, token, { user_code: cfg.userCode, featured_image: att, seo_title: cfg.seoTitle || undefined }));
    if (cfg.insertContent) {
      const payload = { user_code: cfg.userCode, content: imgHtml(url), position: cfg.insertPosition || 'end' };
      if (payload.position === 'before' || payload.position === 'after') payload.h2_number = Number(cfg.h2Index || 1);
      log.push(await callJson(`${site}/wp-json/wp-gpt/v1/append-content/${id}`, token, payload));
    }
  }

  if (cfg.contentType === 'page') {
    if (cfg.featured || cfg.seoTitle) log.push(await callJson(`${site}/wp-json/wp-gpt/v1/manage-page-with-user-code/`, token, { user_code: cfg.userCode, action: 'update-page', page_id: id, featured_image: cfg.featured ? att : undefined, seo_title: cfg.seoTitle || undefined }));
    if (cfg.insertContent) {
      const current = await callJson(`${site}/wp-json/wp-gpt/v1/manage-page-with-user-code/`, token, { user_code: cfg.userCode, action: 'retrieve', page_id: id });
      const old = current?.data?.post_content || current?.post_content || '';
      const next = cfg.insertPosition === 'start' ? `${imgHtml(url)}\n${old}` : `${old}\n${imgHtml(url)}`;
      log.push(await callJson(`${site}/wp-json/wp-gpt/v1/manage-page-with-user-code/`, token, { user_code: cfg.userCode, action: 'update-page', page_id: id, post_content: next }));
    }
  }

  if (cfg.contentType === 'product') {
    if (cfg.featured) log.push(await callJson(`${site}/wp-json/wp-gpt/v1/update-featured-image-product/${id}`, token, { user_code: cfg.userCode, featured_image: att }));
    if (cfg.gallery) log.push(await callJson(`${site}/wp-json/wp-gpt/v1/insert-image-in-gallery-product/${id}`, token, { user_code: cfg.userCode, image_id: att }));
    if (cfg.seoTitle) log.push(await callJson(`${site}/wp-json/wp-gpt/v1/update-seo-title-product/${id}`, token, { user_code: cfg.userCode, seo_title: cfg.seoTitle }));
    if (cfg.insertContent) log.push(await callJson(`${site}/wp-json/wp-gpt/v1/append-html-in-long-description/${id}`, token, { user_code: cfg.userCode, html: imgHtml(url), position: cfg.insertPosition || 'end' }));
  }

  return log;
}

async function uploadChunked(site, token, cfg, file, onProgress) {
  const plan = getChunkPlan(file.base64.length);
  const chunks = splitChunks(file.base64, plan.chunkSize);
  let uploadId = "";

  if (onProgress) {
    onProgress({ stage: "start_file", file_name: file.filename, total_chunks: plan.totalChunks, chunk_size: plan.chunkSize });
  }
  for (let i = 0; i < chunks.length; i += 1) {
    const last = i === chunks.length - 1;
    if (onProgress) {
      onProgress({ stage: "chunk_uploading", file_name: file.filename, chunk_index: i, total_chunks: plan.totalChunks });
    }

    const res = await callJson(`${site}/wp-json/wp-gpt/v1/manage-file/`, token, {
      action: 'send-image-base-64-in-chunk',
      user_code: cfg.userCode,
      filename: file.filename,
      mime_type: file.mimeType,
      chunk_base64: chunks[i],
      chunk_index: i,
      total_chunks: plan.totalChunks,
      upload_id: uploadId || undefined,
      is_last_chunk: last,
      title: file.filename,
      alt: file.filename,
    });
    if (!last) {
      uploadId = res.upload_id;
      if (onProgress) {
        onProgress({ stage: "chunk_uploaded", file_name: file.filename, chunk_index: i, total_chunks: plan.totalChunks, upload_id: uploadId });
      }
    } else {
      const f = res?.files?.[0] || {};
      const upload = { attachment_id: f.id, media_url: f.url, file_name: f.name || file.originalName, total_chunks: plan.totalChunks, chunk_size: plan.chunkSize };
      const operations = await postOps(site, token, cfg, upload);
      if (onProgress) {
        onProgress({ stage: "file_completed", file_name: upload.file_name, attachment_id: upload.attachment_id, media_url: upload.media_url, total_chunks: plan.totalChunks });
      }
      return { ...upload, operations };
    }
  }
  throw new Error('Finalize non raggiunto');
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== 'WWWW_UPLOAD_CHUNKED') return;
  (async () => {
    try {
      const cfg = message.settings || {};
      const site = norm(cfg.siteUrl);
      const token = cfg.bearerToken;
      const files = message.files || [];
      const uploads = [];
      const progress = [];
      const publishProgress = (event) => {
        progress.push(event);
        chrome.runtime.sendMessage({ type: "WWWW_UPLOAD_PROGRESS", progress: event });
      };

      for (const file of files) uploads.push(await uploadChunked(site, token, cfg, file, publishProgress));
      sendResponse({ ok: true, log: { uploads, progress } });
    } catch (error) {
      sendResponse({ ok: false, error: error.message });
    }
  })();
  return true;
});