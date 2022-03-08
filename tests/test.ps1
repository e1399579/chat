cd "E:\project\chat\tests"
for (($i = 0); $i -lt 50; $i++)
{
    Start-Process -FilePath "powershell" -ArgumentList "/c","node .\test_connections.js"
    $s = 5 + $i
    Start-Sleep -s $s
}