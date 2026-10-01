const { MinimalHttp, } = require(process.env.GEN_CJS + '/http');
const { TokenStore } = require(process.env.GEN_CJS + '/tokens');
const { isReadOnlyError, ReadOnlyError } = require(process.env.GEN_CJS + '/errors');
const assert = require('assert');
function mk(status, body, hdr) { global.fetch = async () => ({ status, headers: { get: (k) => k.toLowerCase()==='x-subscription-state' ? (hdr ?? null) : null }, json: async () => body }); }
(async () => {
  const toasts = []; const notices = [];
  const h = new MinimalHttp('http://x/', new TokenStore());
  h.setNotifier((m) => toasts.push(m)); h.setSubscriptionNoticeListener((n) => notices.push(n));
  mk(423, {status:'error',message:'ro',data:{error_code:'READ_ONLY_TRIAL_EXPIRED',ended_at:'2026-09-10T00:00:00Z',reason:'trial_expired',is_trial:true}}, 'read_only; ended_at=2026-09-10T00:00:00Z; reason=trial_expired');
  let err; try { await h.post('/a', {}); } catch (e) { err = e; }
  assert(isReadOnlyError(err) && err instanceof ReadOnlyError); assert.equal(err.errorCode,'READ_ONLY_TRIAL_EXPIRED'); assert.equal(err.endedAt,'2026-09-10T00:00:00Z'); assert.equal(err.httpStatus,423);
  assert.equal(toasts.length,0); assert.equal(notices.length,1); assert.equal(notices[0].state,'read_only');
  // no header: derived from payload
  notices.length=0; mk(423, {status:'error',message:'ro',data:{error_code:'READ_ONLY_PLAN_ENDED',ended_at:'2026-09-10T00:00:00Z'}});
  try { await h.post('/a', {}); } catch (e) { err = e; }
  assert.equal(err.errorCode,'READ_ONLY_PLAN_ENDED'); assert.equal(notices[0].endedAt,'2026-09-10T00:00:00Z');
  // e423 handler receives ReadOnlyError, resolves
  let got; mk(423, {status:'error',message:'ro',data:{error_code:'READ_ONLY_NO_SUBSCRIPTION'}});
  const r = await h.post('/a', {}, undefined, { e423: (e) => { got = e; return 'handled'; } });
  assert.equal(r,'handled'); assert(isReadOnlyError(got));
  // success with header
  notices.length=0; mk(200, {status:'ok',data:{x:1}}, 'trial_ending; ends_at=2026-10-04T00:00:00Z; days=3');
  assert.deepEqual(await h.get('/a'), {x:1}); assert.equal(notices[0].state,'trial_ending'); assert.equal(notices[0].daysRemaining,3);
  // 423 w/o READ_ONLY code is generic (toast)
  mk(423, {status:'error',message:'locked',data:{}}); try { await h.get('/a'); } catch (e) { err = e; }
  assert(!isReadOnlyError(err)); assert.equal(toasts.length,1);
  // 423 never triggers refresh handler
  let refreshed=false; h.setRefreshHandler(async()=>{refreshed=true;}); mk(423,{status:'error',message:'ro',data:{error_code:'READ_ONLY_TRIAL_EXPIRED'}}); try{await h.post('/a',{});}catch{} assert(!refreshed);
  console.log('generated-client 423 OK');
})().catch(e=>{console.error(e);process.exit(1)});
