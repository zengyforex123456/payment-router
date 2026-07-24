<?php
declare(strict_types=1);

namespace Converge\Core\Contract;

/**
 * ModuleContract — 模块对外 API 契约 (P3)
 *
 * 每个模块通过 Contract 接口暴露其公开能力。
 * 其他模块通过 ModuleLoader::getContract('ModuleName') 获取，
 * 不直接实例化模块内部类。
 *
 * 规则:
 *   - 每个 Contract ≤5 方法 (超过→拆分)
 *   - 参数用 DTO/值对象 (≤4 个标量参数)
 *   - 返回类型明确 (不返回 array|null)
 *   - 异常语义化 (不抛通用 \Exception)
 */
interface ModuleContract
{
    /** 返回模块名称 (匹配 module.json 的 name) */
    public static function moduleName(): string;
}
