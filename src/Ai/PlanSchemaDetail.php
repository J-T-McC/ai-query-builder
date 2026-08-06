<?php

declare(strict_types=1);

namespace JTMcC\AiQueryBuilder\Ai;

/**
 * How much of the contract the emitted plan schema restates.
 *
 * The enums a schema carries are an accuracy optimisation, not a security
 * control. PlanValidator re-derives every permitted column, operator, aggregate
 * and bucket from the same contract and fails closed, so dropping an enum costs
 * at most one correction round-trip — never safety, and never the guarantee
 * that a column hidden from a user is reported as unknown.
 *
 * What it buys is growth: an enumerated schema is O(resources × columns ×
 * filterDepth), a generic one is constant. Measure both for a real schema with
 * `ai-query:describe {resource} --cost`.
 */
enum PlanSchemaDetail
{
    /**
     * Every column, operator, aggregate and bucket is enumerated.
     *
     * The strongest steering, and the right default while a schema is small
     * enough for it to be cheap.
     */
    case Enumerated;

    /**
     * Columns and operators are described rather than enumerated.
     *
     * The schema stops growing with the contract, which is what makes one tool
     * over many resources affordable. The data dictionary becomes the only
     * thing naming columns, so the model leans on it and on rejections.
     */
    case Generic;

    public function enumerates(): bool
    {
        return $this === self::Enumerated;
    }
}
