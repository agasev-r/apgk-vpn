const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const cscPath = 'C:\\Windows\\Microsoft.NET\\Framework64\\v4.0.30319\\csc.exe';
const sourceFile = path.join(__dirname, 'helper_service.cs');
const outFile = path.join(__dirname, 'helper_service.exe');

console.log('Compiling helper_service.cs...');

try {
  if (fs.existsSync(outFile)) {
    fs.unlinkSync(outFile);
  }
  
  // Compile to Windows executable (no console window) using /target:winexe
  // Wait, Windows Services can just be standard exe.
  execSync(`"${cscPath}" /nologo /out:"${outFile}" /reference:System.Web.Extensions.dll "${sourceFile}"`);
  console.log('Successfully compiled helper_service.exe');
} catch (error) {
  console.error('Failed to compile helper_service.cs:', error.stdout ? error.stdout.toString() : error.message);
  process.exit(1);
}
