#!/bin/bash
#bash test.sh
#ps -C node -o pid|sed -n '2,$p'|xargs kill
for((i=0;i<50;i++))
do
node ./test_connections.js 255 &
#sleep $((5+i))
done
