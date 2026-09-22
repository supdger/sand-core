<?php

namespace plugin\sandadmin\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SandAdmin plugin scaffold command.
 */
class SandPlugin extends Command
{
    protected static $defaultName = 'sand:plugin';
    protected static $defaultDescription = '创建 SandAdmin 插件';

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Plugin name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $io = new SymfonyStyle($input, $output);

        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            $io->error('插件名称只能是单个目录名。');
            return Command::FAILURE;
        }

        $target = base_path() . '/plugin/' . $name;
        if (is_dir($target)) {
            $io->error("目录 {$target} 已存在。");
            return Command::FAILURE;
        }

        $directories = [
            '/app/admin/controller',
            '/app/admin/logic',
            '/app/api/controller',
            '/app/api/logic',
            '/app/cache',
            '/app/event',
            '/app/model',
            '/app/middleware',
            '/config',
        ];
        foreach ($directories as $directory) {
            mkdir($target . $directory, 0777, true);
        }

        file_put_contents($target . '/app/functions.php', "<?php\n");
        file_put_contents($target . '/config/app.php', "<?php\n\nreturn [\n    'debug' => true,\n];\n");
        file_put_contents($target . '/config/route.php', "<?php\n\nreturn [];\n");

        $io->success("SandAdmin 插件 {$name} 已创建。请按 Sand 平台命名与 PostgreSQL 规范补齐模型、迁移和管理界面。");
        return Command::SUCCESS;
    }
}
