// Child process only. Fixed synthetic SQLite file passed by parent test, no providers.
import { LocalSqliteStorage } from './support.mjs';
import { AuthorityJournal } from '../authority-journal.mjs';
const storage=new LocalSqliteStorage(process.argv[2]);
storage.db.exec('PRAGMA synchronous=FULL');
const root=new AuthorityJournal(storage,()=>1001);
if(process.argv[3]==='before_commit'){
 storage.db.exec('BEGIN IMMEDIATE');
 const d=root.read();d.leases.push('synthetic-inflight');d.revision++;
 storage.db.prepare('UPDATE df_security_authority SET revision=?,document=? WHERE id=1').run(d.revision,JSON.stringify(d));
}else if(process.argv[3]==='after_commit')root.lease('synthetic-inflight');
else throw Error('INVALID_TEST_PHASE');
process.kill(process.pid,'SIGKILL');
