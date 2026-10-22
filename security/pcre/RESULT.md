# CVE-2026-103111 嵌入 PCRE 修复结果（候选，未部署）

## 结论

实际隔离构建成功，不是仅候选源码或关闭 JIT。候选 runtime 镜像：
`epay-pcre-candidate:20261022-ghsa-r9hj`
`sha256:86bf3df70e4511eb6d8054345e479452233575aea52d958a1b97f6023f0c7741`。

PHP 保持 8.3.36；从相同官方 tarball 重编译 CLI/FPM，应用官方 PCRE
10.49 大栈分配修复，JIT 保持 On。运行时 PCRE_VERSION 仍为 10.42 是正常
回补结果，不靠版本字符串认定修复，也不靠 APK 10.49 冒充修复。

## 强证据与验证

- 已读取官方 GHSA-r9hj-j2rw-4q3m 和 SUPPORT-LIFECYCLE 的 10.42 回补要求，原文快照在本目录。
- 固定官方 PHP 8.3.36 镜像 manifest digest；校验 PHP tarball、未改 PCRE 编译单元、补丁 SHA256，失败即停止。
- 官方补丁仅去掉 PHP 未携带的 testdata 部分，第四 hunk 的上下文 LOCAL1 改 LOCALS1；增加/删除代码行与官方逐行相同（21 行），JSON 对比证据已保存。
- **实际编译后的完整 pcre2_jit_compile.c 与官方 release/pcre2-10.42 分支 commit c49acf8b041e5a8f4ad935661f0b04f563a4ab74 字节完全一致**，SHA256=`103e398cbd11d2703fae6063d985cb1f469bceb8dc58c3056cf04cf7ceac0bb4`。
- 编译日志记录 fuzz=0 四个 hunk 成功及 make 编译；源码和构建二进制哈希保留在镜像 `/usr/local/share/epay-pcre-fix/`。
- CLI SHA256=`f013446ca5ff1bd1a957edb9cc727185438209cdcc43722a1961059c4bc0e3df`；FPM SHA256=`502a510791044324d98168fe7a35c43d7afb64391233ef0879508853f8597f15`；运行镜像读回与构建产物一致。
- 正常回归 24 条 PASS：PHP 版本、JIT、现有扩展、ASCII/金额命名捕获/UTF/替换/分割/match_all/前后断言/交替/不匹配及安全 INI。
- 原镜像与候选的 PHP 版本、完整扩展集合、configure options 完全相同；全部 PHP conf.d 和 FPM 配置文件 SHA256 完全相同。
- `php-fpm -tt` exit 0；`security.limit_extensions=.php`、cgi.fix_pathinfo=Off、错误显示关闭、log_errors=On 均读回确认。
- CLI/FPM ldd 无缺失依赖，不链接系统 libpcre2。

证据目录：`/root/.hermes/cache/scratch/epay-round2-tests/pcre/`：
`build.log`、`build-first-context-failure.log`、`regression.txt`、`fpm-test.txt`、
`runtime-evidence.txt`、`parity.json`、`config-parity.sha256`、
`source-official-parity.txt`、`patch-equivalence.json`。

## 变更、边界和阻碍

仅本代理改 Dockerfile 和 security/pcre/；未改 103Epay.sh。共享 repo 其他代理有并发修改，不归本代理所有，不回滚。
首次构建因官方平面补丁 LOCAL1/LOCALS1 上下文差异失败，明确修正后第二次实际构建成功。
扩展安装清理 APK 缓存时有 cached index missing 警告，构建最终 exit 0，不是编译失败。
本次构建 runtime target，未将业务源码/配置/生产 secrets 复制进镜像，也未发布/部署、未覆盖任何 epay-php tag。

未运行漏洞触发 PoC/ASAN；正常回归不能替代内存安全测试。结论限此官方修复进入实际 runtime，
不是整个 PCRE lifecycle 全量 CVE 关闭。未因固定 PHP 版本回退已修的 PHP CVE。
工具链 APK 未固定快照，未来可重复此源码/补丁过程，但不声称 bit-for-bit reproducible。

## 父集成必要条件

详见 README.md。根 Dockerfile context 是 repo；父生成 context 中应将 Dockerfile 和
`security/pcre/rebuild.sh`、`security/pcre/php-8.3.36-pcre2-large-stack.patch` 放在根，
业务源码放 html/，最后 COPY 改为 html/。不可只把补丁塞进 html/；不应把审计/构建资料放入可服务 webroot。
保留多阶段和 runtime 配置逻辑。父集成仍须用最终业务 artifact 跑整合回归；本报告不是生产放行。
