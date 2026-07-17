const cp = require('child_process');
cp.execFile('sc.exe', ['query', 'type=', 'service', 'state=', 'all'], { maxBuffer: 1024*1024*10 }, (err, stdout) => {
  if(err) throw err;
  const blocks = stdout.split(/\r?\n\r?\n/);
  console.log('Total blocks:', blocks.length);
  for (const block of blocks) {
    const lines = block.split(/\r?\n/);
    let tempName = null;
    let isRun = false;
    for (const line of lines) {
      if (line.includes('WireGuardTunnel$')) {
        const m = line.match(/WireGuardTunnel\$([A-Za-z0-9_-]+)/i);
        if (m) tempName = m[1];
      }
      if (line.toUpperCase().includes('RUNNING') || line.includes(': 4') || line.includes(' 4 ')) {
        isRun = true;
      }
    }
    if (tempName) {
      console.log('Found block for:', tempName, 'isRun:', isRun);
    }
  }
});
