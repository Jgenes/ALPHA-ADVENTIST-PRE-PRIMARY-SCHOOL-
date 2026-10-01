'use strict';
const {
  S3Client, HeadBucketCommand, GetObjectCommand, PutObjectCommand,
  DeleteObjectCommand, ListObjectsV2Command
} = require('@aws-sdk/client-s3');

const ENV_KEYS = ['SUPABASE_S3_ENDPOINT', 'SUPABASE_S3_REGION', 'SUPABASE_S3_ACCESS_KEY_ID', 'SUPABASE_S3_SECRET_ACCESS_KEY', 'SUPABASE_STORAGE_BUCKET'];
const KEY_PATTERN = /^[a-f0-9]{48}$/;

function supabaseStorageConfig(env = process.env) {
  const values = ENV_KEYS.map(key => env[key] || '');
  if (!values.some(Boolean)) return null;
  if (values.some(value => !value)) throw new Error('Configure all Supabase S3 endpoint, region, access key, secret key and bucket settings.');
  let endpoint;
  try { endpoint = new URL(env.SUPABASE_S3_ENDPOINT); } catch { throw new Error('SUPABASE_S3_ENDPOINT must be a valid HTTPS URL.'); }
  if (endpoint.protocol !== 'https:' || endpoint.username || endpoint.password || endpoint.search || endpoint.hash) throw new Error('SUPABASE_S3_ENDPOINT must be a trusted HTTPS endpoint without credentials or query parameters.');
  if (!/^[a-z0-9-]+$/i.test(env.SUPABASE_S3_REGION)) throw new Error('SUPABASE_S3_REGION is invalid.');
  if (!/^[a-z0-9][a-z0-9._-]{0,61}[a-z0-9]$/.test(env.SUPABASE_STORAGE_BUCKET)) throw new Error('SUPABASE_STORAGE_BUCKET is invalid.');
  return {
    endpoint: endpoint.origin + endpoint.pathname.replace(/\/+$/, ''),
    region: env.SUPABASE_S3_REGION,
    accessKeyId: env.SUPABASE_S3_ACCESS_KEY_ID,
    secretAccessKey: env.SUPABASE_S3_SECRET_ACCESS_KEY,
    bucket: env.SUPABASE_STORAGE_BUCKET
  };
}

function createSupabaseStorage(config, client) {
  if (!config) return null;
  const s3 = client || new S3Client({
    endpoint: config.endpoint,
    region: config.region,
    forcePathStyle: true,
    credentials: { accessKeyId: config.accessKeyId, secretAccessKey: config.secretAccessKey },
    requestChecksumCalculation: 'WHEN_REQUIRED',
    responseChecksumValidation: 'WHEN_REQUIRED'
  });
  function objectKey(area, key) {
    if (!['private', 'public'].includes(area) || !KEY_PATTERN.test(key || '')) throw new Error('Invalid object storage key.');
    return `${area}/${key}`;
  }
  return {
    bucket: config.bucket,
    async check() { await s3.send(new HeadBucketCommand({ Bucket: config.bucket })); },
    async get(area, key) {
      const result = await s3.send(new GetObjectCommand({ Bucket: config.bucket, Key: objectKey(area, key) }));
      if (!result.Body) throw new Error('Stored object has no body.');
      return Buffer.from(await result.Body.transformToByteArray());
    },
    async put(area, key, body) {
      await s3.send(new PutObjectCommand({ Bucket: config.bucket, Key: objectKey(area, key), Body: body, ContentType: 'application/octet-stream' }));
    },
    async remove(area, key) {
      await s3.send(new DeleteObjectCommand({ Bucket: config.bucket, Key: objectKey(area, key) }));
    },
    async isEmpty() {
      const result = await s3.send(new ListObjectsV2Command({ Bucket: config.bucket, MaxKeys: 1 }));
        return (result.KeyCount || result.Contents?.length || 0) === 0;
    }
  };
}

module.exports = { supabaseStorageConfig, createSupabaseStorage };