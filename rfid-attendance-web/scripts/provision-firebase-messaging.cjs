// Run after firebase login. Reuses the Firebase CLI identity; never logs credentials.
const fs = require('node:fs');
const path = require('node:path');
const cliRoot = path.join(process.env.APPDATA, 'npm/node_modules/firebase-tools/lib');
const auth = require(path.join(cliRoot, 'auth.js'));
const project = 'jsmkj-campus-push-2026';
const number = '349801355685';
const privateDir = path.resolve(__dirname, '../storage/private');

async function main() {
  const account = auth.getGlobalDefaultAccount();
  if (account?.user?.email !== 'jsmilano31@gmail.com') throw new Error('Select the CampusTrack Firebase CLI account first.');
  const credentials = await auth.getAccessToken(account.tokens.refresh_token, ['https://www.googleapis.com/auth/cloud-platform']);
  async function request(url, method = 'GET', body, allow404 = false) {
    const response = await fetch(url, {method, headers: {Authorization: `Bearer ${credentials.access_token}`, 'Content-Type': 'application/json'}, body: body === undefined ? undefined : JSON.stringify(body)});
    const value = await response.json().catch(() => ({}));
    if (allow404 && response.status === 404) return null;
    if (!response.ok) throw new Error(`${method} ${new URL(url).hostname}: HTTP ${response.status} ${value.error?.message || 'request failed'}`);
    return value;
  }
  let operation = await request(`https://serviceusage.googleapis.com/v1/projects/${number}/services:batchEnable`, 'POST', {serviceIds: ['iam.googleapis.com', 'fcm.googleapis.com', 'fcmregistrations.googleapis.com', 'firebaseinstallations.googleapis.com']});
  while (!operation.done) {
    await new Promise(resolve => setTimeout(resolve, 1500));
    operation = await request(`https://serviceusage.googleapis.com/v1/${operation.name}`);
  }
  if (operation.error) throw new Error(operation.error.message);
  console.log('Messaging and IAM APIs enabled.');
  const email = `campus-notification-sender@${project}.iam.gserviceaccount.com`;
  let sender = await request(`https://iam.googleapis.com/v1/projects/${project}/serviceAccounts/${email}`, 'GET', undefined, true);
  if (!sender) sender = await request(`https://iam.googleapis.com/v1/projects/${project}/serviceAccounts`, 'POST', {accountId: 'campus-notification-sender', serviceAccount: {displayName: 'CampusTrack PHP notification sender'}});
  const policy = await request(`https://cloudresourcemanager.googleapis.com/v1/projects/${project}:getIamPolicy`, 'POST', {});
  policy.bindings ||= [];
  const role = 'roles/firebasecloudmessaging.admin';
  let binding = policy.bindings.find(entry => entry.role === role && !entry.condition);
  if (!binding) policy.bindings.push(binding = {role, members: []});
  const member = `serviceAccount:${email}`;
  if (!binding.members.includes(member)) {
    binding.members.push(member);
    await request(`https://cloudresourcemanager.googleapis.com/v1/projects/${project}:setIamPolicy`, 'POST', {policy});
  }
  console.log('Dedicated sender granted Firebase Cloud Messaging API Admin only.');
  fs.mkdirSync(privateDir, {recursive: true});
  const keyFile = path.join(privateDir, 'firebase-service-account.json');
  if (!fs.existsSync(keyFile)) {
    const key = await request(`https://iam.googleapis.com/v1/projects/${project}/serviceAccounts/${email}/keys`, 'POST', {privateKeyType: 'TYPE_GOOGLE_CREDENTIALS_FILE', keyAlgorithm: 'KEY_ALG_RSA_2048'});
    fs.writeFileSync(keyFile, Buffer.from(key.privateKeyData, 'base64'), {mode: 0o600, flag: 'wx'});
    console.log('Sender credential saved to ignored private storage.');
  } else console.log('Existing private sender credential retained.');
  const services = await request(`https://serviceusage.googleapis.com/v1/projects/${number}/services?filter=state:ENABLED&pageSize=100`);
  console.log('Database services enabled:', (services.services || []).filter(service => /firestore|firebasedatabase/.test(service.config?.name || '')).map(service => service.config.name));
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
