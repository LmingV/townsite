# ============================================================
#  修掉 wiki-md.js 里的 NUL 字节
#  ------------------------------------------------------------
#  用法：右键这个文件 →「使用 PowerShell 运行」
#        或在终端执行：
#            powershell -ExecutionPolicy Bypass -File fix-nul.ps1
#
#  背景：markdown 渲染器用 NUL 字节当占位符（抽出代码块，
#  避免里面的 * _ 被当成强调）。功能正常，但 NUL 会让 git
#  把文件当二进制，而且 Windows 上有些编辑器会静默删掉它，
#  把文件改坏。
#
#  修法：把每个 NUL 换成 U+E000（Unicode 私用区字符）。
#  它同样不可能出现在正常正文里，但是合法的 UTF-8，
#  git 会正常当文本处理。占位符和还原用的正则里都是同一个
#  字节，一起替换后仍然互相匹配，逻辑不变。
#
#  跑完可以删掉这个脚本，它只需要执行一次。
# ============================================================

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$target = 'wiki-md.js'

if (-not (Test-Path $target)) {
  Write-Host "[X] 找不到 $target" -ForegroundColor Red
  Read-Host '按回车退出'; exit 1
}

$bytes = [System.IO.File]::ReadAllBytes($target)
$nulCount = ($bytes | Where-Object { $_ -eq 0 }).Count

Write-Host ''
Write-Host "文件：$target  ($($bytes.Length) 字节)"
Write-Host "NUL 字节：$nulCount 个"
Write-Host ''

if ($nulCount -eq 0) {
  Write-Host '[OK] 没有 NUL 字节，不需要修。' -ForegroundColor Green
  Read-Host '按回车退出'; exit 0
}

# 先备份，万一出问题能还原
$backup = "$target.bak"
[System.IO.File]::WriteAllBytes($backup, $bytes)
Write-Host "已备份到 $backup" -ForegroundColor DarkGray

# 0x00  →  0xEE 0x80 0x80（U+E000 的 UTF-8 编码）
$out = New-Object System.Collections.Generic.List[byte]
foreach ($b in $bytes) {
  if ($b -eq 0) {
    $out.Add([byte]0xEE); $out.Add([byte]0x80); $out.Add([byte]0x80)
  } else {
    $out.Add($b)
  }
}
[System.IO.File]::WriteAllBytes($target, $out.ToArray())

# 验证
$after = [System.IO.File]::ReadAllBytes($target)
$leftNul = ($after | Where-Object { $_ -eq 0 }).Count
$marker  = 0
for ($i = 0; $i -lt $after.Length - 2; $i++) {
  if ($after[$i] -eq 0xEE -and $after[$i+1] -eq 0x80 -and $after[$i+2] -eq 0x80) { $marker++ }
}

Write-Host ''
Write-Host "剩余 NUL：$leftNul  (应为 0)"
Write-Host "新占位符：$marker 个  (应为 $nulCount)"
Write-Host ''

if ($leftNul -eq 0 -and $marker -eq $nulCount) {
  Write-Host '[OK] 修好了。' -ForegroundColor Green
  Write-Host ''
  Write-Host '接下来：' -ForegroundColor Yellow
  Write-Host '  1. 用浏览器打开 wiki.html?p=manual 确认页面正常'
  Write-Host '  2. 确认没问题后删掉备份：del wiki-md.js.bak'
  Write-Host '  3. 提交：git add -A ; git commit -m "修掉 wiki-md.js 的 NUL 字节"'
} else {
  Write-Host '[X] 结果不对，已还原' -ForegroundColor Red
  [System.IO.File]::WriteAllBytes($target, $bytes)
}

Write-Host ''
Read-Host '按回车退出'
