const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const cscPath = "C:\\Windows\\Microsoft.NET\\Framework64\\v4.0.30319\\csc.exe";
const nsisPath = "C:\\Users\\agasev\\AppData\\Local\\electron-builder\\Cache\\nsis\\nsis-3.0.4.1\\makensis.exe";

try {
  console.log('Compiling C# service...');
  execSync(`"${cscPath}" /nologo /out:"${path.join(__dirname, 'WireGuardHelper.exe')}" "${path.join(__dirname, 'helper.cs')}"`);
  console.log('Compiled WireGuardHelper.exe successfully.');

  console.log('Compiling NSIS installer...');
  execSync(`"${nsisPath}" "${path.join(__dirname, 'installer.nsi')}"`);
  console.log('Compiled installer Setup.exe successfully.');
} catch (e) {
  console.error('Compilation failed:', e.message);
} finally {
  // clean up
  try {
    fs.unlinkSync(path.join(__dirname, 'WireGuardHelper.exe'));
  } catch {}
}

console.log('Done!');
