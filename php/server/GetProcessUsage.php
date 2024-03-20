<?php
namespace server;

class GetProcessUsage {
    /**
     * @param int $pid
     * @return float percent
     * @see https://www.baeldung.com/linux/total-process-cpu-usage
     * @see https://stackoverflow.com/questions/1221555/retrieve-cpu-usage-and-memory-usage-of-a-single-process-on-linux
     */
    public function __invoke($pid): float {
        $percent = `top -p {$pid} -d 0.1 -b -n 2|tail -1|awk '{print $9}'`;
        return (float) trim($percent);
    }
}