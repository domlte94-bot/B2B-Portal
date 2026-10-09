<?php
// Builds vendor/autoload_packages.php for a source checkout of WooCommerce.
$root = $argv[1];
$psr4 = []; $psr0 = []; $classmap = []; $files = [];
$pkgs = [ $root => json_decode(file_get_contents("$root/composer.json"), true) ];
foreach (array_merge(glob("$root/vendor/*/*/composer.json"), glob("$root/packages/*/composer.json")) as $cj) {
  $pkgs[dirname($cj)] = json_decode(file_get_contents($cj), true);
}
function scan($dir, &$map) {
  if (is_file($dir)) { $it = [new SplFileInfo($dir)]; }
  elseif (is_dir($dir)) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)); }
  else return;
  foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $path = $f->getPathname();
    if (preg_match('#/(tests?|Tests)/#', $path)) continue;
    $src = @file_get_contents($path);
    if (!$src) continue;
    $toks = @token_get_all($src);
    $ns = ''; $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
      $t = $toks[$i]; if (!is_array($t)) continue;
      if ($t[0] === T_NAMESPACE) { $ns=''; for ($j=$i+1;$j<$n;$j++){ $u=$toks[$j]; if ($u===';'||$u==='{') break; if (is_array($u) && in_array($u[0],[T_STRING,T_NAME_QUALIFIED,T_NS_SEPARATOR])) $ns.=$u[1]; } }
      if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, defined('T_ENUM')?T_ENUM:-1], true)) {
        // skip ::class and anonymous classes
        $k=$i-1; while($k>=0 && is_array($toks[$k]) && $toks[$k][0]===T_WHITESPACE) $k--;
        if ($k>=0 && (is_array($toks[$k]) ? in_array($toks[$k][0],[T_DOUBLE_COLON,T_NEW]) : false)) continue;
        for ($j=$i+1;$j<$n;$j++){ $u=$toks[$j]; if (is_array($u) && $u[0]===T_STRING){ $cls = ltrim($ns.'\\'.$u[1],'\\'); if(!isset($map[$cls])) $map[$cls]=$path; break;} if ($u==='{'||$u==='(') break; }
      }
    }
  }
}
foreach ($pkgs as $dir => $cfg) {
  $a = $cfg['autoload'] ?? [];
  foreach ($a['psr-4'] ?? [] as $pre => $paths) foreach ((array)$paths as $p) $psr4[] = [$pre, "$dir/$p"];
  foreach ($a['psr-0'] ?? [] as $pre => $paths) foreach ((array)$paths as $p) $psr0[] = [$pre, "$dir/$p"];
  foreach ($a['classmap'] ?? [] as $p) scan(rtrim("$dir/$p",'/'), $classmap);
  foreach ($a['files'] ?? [] as $p) $files[] = "$dir/$p";
}
// Action Scheduler is loaded by WooCommerce itself.
$out = "<?php\n// Generated for a test install.\n\$b2bcp_classmap = " . var_export($classmap, true) . ";\n\$b2bcp_psr4 = " . var_export($psr4, true) . ";\n\$b2bcp_psr0 = " . var_export($psr0, true) . ";\n";
$out .= <<<'PHP'
spl_autoload_register(function ($class) use ($b2bcp_classmap, $b2bcp_psr4, $b2bcp_psr0) {
  if (isset($b2bcp_classmap[$class])) { require_once $b2bcp_classmap[$class]; return; }
  foreach ($b2bcp_psr4 as [$pre, $dir]) {
    if ($pre !== '' && strpos($class, $pre) !== 0) continue;
    $f = rtrim($dir,'/') . '/' . str_replace('\\','/', substr($class, strlen($pre))) . '.php';
    if (is_file($f)) { require_once $f; return; }
  }
  foreach ($b2bcp_psr0 as [$pre, $dir]) {
    if (strpos($class, $pre) !== 0) continue;
    $f = rtrim($dir,'/') . '/' . str_replace(['\\','_'],'/', $class) . '.php';
    if (is_file($f)) { require_once $f; return; }
  }
});
PHP;
$out .= "\n";
foreach ($files as $f) $out .= "require_once " . var_export($f, true) . ";\n";
file_put_contents("$root/vendor/autoload_packages.php", $out);
echo count($classmap), " classes, ", count($psr4), " psr4, ", count($files), " files\n";
