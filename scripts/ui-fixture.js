'use strict';
// Node is used only to automate a browser against the PHP server, never by the app.
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
class Agent {
  constructor(base) { this.base=base; this.cookie=''; this.csrf=''; }
  async request(method, route, body, options={}) {
    const headers={Cookie:this.cookie,...(body===undefined?{}:{'Content-Type':'application/json',...(options.csrf===false?{}:{'X-CSRF-Token':this.csrf})}),...options.headers};
    const res=await fetch(this.base+route,{method,headers,redirect:'manual',...(body===undefined?{}:{body:JSON.stringify(body)})});
    const cookie=res.headers.get('set-cookie');if(cookie)this.cookie=cookie.split(';')[0];
    const bytes=Buffer.from(await res.arrayBuffer());let json;try{json=JSON.parse(bytes.toString());}catch{}
    if(json?.csrf)this.csrf=json.csrf;return {status:res.status,headers:res.headers,bytes,text:bytes.toString(),json,data:json?.data??json};
  }
  async ok(method,route,body,options){const r=await this.request(method,route,body,options);assert.equal(r.status,200,`${method} ${route}: ${r.text.slice(0,500)}`);return r.data;}
  async login(user,password){await this.ok('GET','/api/auth/session');return this.ok('POST','/api/auth/login',{username:user.username,password});}
}
async function fixture() {
  const dir=await fs.mkdtemp(path.join(os.tmpdir(),'alpha-php-ui-'));
  const listener=net.createServer();await new Promise(r=>listener.listen(0,'0.0.0.0',r));const port=listener.address().port;await new Promise(r=>listener.close(r));
  const base=`http://127.0.0.1:${port}`;
  const env={...process.env,APP_ENV:'test',NODE_ENV:'test',DATA_DIR:dir,BASE_URL:base,UI_TEST_FIXTURE:'true',MYSQL_URL:'',MYSQL_HOST:'',MYSQL_DB_NAME:'',STORAGE_ENCRYPTION_KEY:'',STORAGE_ENCRYPTION_KEY_FILE:'',AUDIT_HMAC_KEY:'',AUDIT_HMAC_KEY_FILE:'',ADMIN_PASSWORD:'',HEAD_TEACHER_USERNAME:'',HEAD_TEACHER_NAME:'',HEAD_TEACHER_PASSWORD:'',HEAD_TEACHER_APPROVED:'',HEAD_TEACHER_APPROVAL_REFERENCE:'',SUPABASE_S3_ENDPOINT:'',SUPABASE_S3_REGION:'',SUPABASE_S3_ACCESS_KEY_ID:'',SUPABASE_S3_SECRET_ACCESS_KEY:'',SUPABASE_STORAGE_BUCKET:'',STORAGE_DRIVER:'local',SMTP_HOST:'',SMTP_PORT:'',SMTP_SECURITY:'',SMTP_USERNAME:'',SMTP_PASSWORD:'',SMTP_PASSWORD_FILE:'',SMTP_FROM_EMAIL:'',SMTP_FROM_NAME:'',GMAIL_SMTP_USER:'',GMAIL_SMTP_APP_PASSWORD:'',OFFICE_ALERT_EMAIL:'',PRIVACY_ALERT_EMAIL:'',OFFICE_ALERT_WEBHOOK_URL:'',OFFICE_ALERT_WEBHOOK_SECRET:''};
  const php=process.env.PHP_BINARY||'php';
  const seeded=JSON.parse(execFileSync(php,['scripts/ui-fixture.php'],{env,encoding:'utf8'}));
  const processHandle=spawn(php,['-S',`0.0.0.0:${port}`,'-t','public','router.php'],{env:{...env,APP_ENV:'development'},stdio:['ignore','pipe','pipe']});let logs='';processHandle.stdout.on('data',x=>logs+=x);processHandle.stderr.on('data',x=>logs+=x);
  try{for(let i=0;i<100;i++){try{const r=await fetch(base+'/readyz');if(r.ok)break;}catch{}if(i===99)throw Error('PHP server did not start: '+logs);await new Promise(r=>setTimeout(r,100));}
    const agents={};for(const [key,user]of Object.entries(seeded.users)){agents[key]=new Agent(base);await agents[key].login(user,seeded.password);}
    return {...seeded,dir,base,config:{environment:'test'},agents,anonymous:new Agent(base),app:{server:{address:()=>({port})}},async close(){processHandle.kill('SIGTERM');await new Promise(r=>processHandle.once('exit',r));await fs.rm(dir,{recursive:true,force:true});}};
  }catch(e){processHandle.kill('SIGTERM');await fs.rm(dir,{recursive:true,force:true});throw e;}
}
module.exports={fixture,Agent};
