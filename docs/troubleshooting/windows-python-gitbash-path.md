# Windows Store Python 无法解析 Git Bash /e/project/ 路径

> Status: 已验证 | Tags: windows, python, git-bash, path

## Detection

```
python3 -c 'open("/e/project/...")' → FileNotFoundError 或 unexpected end of file
```

## Root Cause

Windows Store Python (python3.exe 在 WindowsApps) 使用 Windows 路径解析器，不接受 Git Bash MSYS 转换的 /e/project/ 路径。而 python (非 Python Store 版本) 可以

## Fix

1. PYTHON=$(command -v python || command -v python3)
2. win_path() 函数: sed 's|^/([a-z])/|E:/1:/|'
3. 所有传给 Python 的文件路径先用 win_path 转换

## Verify

```bash
python -c "open('E:/project/...')" 能读取文件
```

### Notes

Git Bash + Windows Python = 路径不兼容。两种修复: win_path 转换或安装非 Store 版本 Python
