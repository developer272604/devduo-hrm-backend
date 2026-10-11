<?php

namespace App\Domain\Payroll\Calculation;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Bounded recursive-descent evaluator. No PHP evaluation, I/O, dynamic functions or floating point.
 *
 * Grammar (all values exact rationals; comparisons yield 1 or 0):
 *   cond := and ('OR' and)* ; and := not ('AND' not)* ; not := 'NOT' not | cmp
 *   cmp  := expr (('>='|'<='|'=='|'!='|'>'|'<') expr)?
 *   expr := term (('+'|'-') term)* ; term := unary (('*'|'/') unary)* ; unary := '-' unary | atom '%'*
 *   atom := number | variable | '(' cond ')' | FUNCTION '(' cond (',' cond)* ')'
 * Functions: MIN MAX (2+ args), ROUND ROUNDUP ROUNDDOWN (x[,places 0..6]), FLOOR CEIL ABS (1), IF(cond,a,b), CLAMP(x,lo,hi).
 */
final class Formula
{
    public const FUNCTIONS = ['MIN', 'MAX', 'ROUND', 'ROUNDUP', 'ROUNDDOWN', 'FLOOR', 'CEIL', 'ABS', 'IF', 'CLAMP'];

    public const KEYWORDS = ['AND', 'OR', 'NOT'];

    private array $tokens = [];
    private int $position = 0;
    private array $variables = [];
    private int $steps = 0;

    public function calculate(string $expression, array $variables = [], int $scale = 2): string
    {
        if ($scale < 0 || $scale > 6 || strlen($expression) > 1000 || trim($expression) === '') throw new InvalidArgumentException('Invalid formula length or precision.');
        preg_match_all('/\G\s*(\d+(?:\.\d+)?|[A-Z][A-Z0-9_]*|>=|<=|==|!=|[<>()+*\/,%\-])/', trim($expression), $matches);
        if (preg_replace('/\s+/', '', $expression) !== implode('', $matches[1]) || count($matches[1]) > 400) throw new InvalidArgumentException('Formula contains unsupported syntax.');
        $this->tokens = $matches[1]; $this->position = 0; $this->variables = $variables; $this->steps = 0;
        try {
            $result = $this->cond(0);
            if ($this->position !== count($this->tokens)) throw new InvalidArgumentException('Unexpected formula token.');
            return (string) $result->toScale($scale, RoundingMode::HALF_UP);
        } catch (\Brick\Math\Exception\MathException $e) {
            throw new InvalidArgumentException('Formula arithmetic error: '.$e->getMessage());
        }
    }

    private function tick(): void
    {
        if (++$this->steps > 2000) throw new InvalidArgumentException('Formula is too complex.');
    }

    private function bool(bool $b): BigRational { return BigRational::of($b ? 1 : 0); }

    private function cond(int $depth): BigRational
    {
        $left = $this->andc($depth + 1);
        while ($this->peek() === 'OR') { $this->position++; $right = $this->andc($depth + 1); $left = $this->bool(!$left->isZero() || !$right->isZero()); }
        return $left;
    }

    private function andc(int $depth): BigRational
    {
        $left = $this->notc($depth + 1);
        while ($this->peek() === 'AND') { $this->position++; $right = $this->notc($depth + 1); $left = $this->bool(!$left->isZero() && !$right->isZero()); }
        return $left;
    }

    private function notc(int $depth): BigRational
    {
        if ($depth > 32) throw new InvalidArgumentException('Formula is nested too deeply.');
        if ($this->peek() === 'NOT') { $this->position++; return $this->bool($this->notc($depth + 1)->isZero()); }
        $left = $this->expression($depth + 1);
        if (in_array($this->peek(), ['>=', '<=', '==', '!=', '>', '<'], true)) {
            $op = $this->tokens[$this->position++]; $right = $this->expression($depth + 1);
            $c = $left->compareTo($right);
            return $this->bool(match ($op) { '>=' => $c >= 0, '<=' => $c <= 0, '==' => $c === 0, '!=' => $c !== 0, '>' => $c > 0, '<' => $c < 0 });
        }
        return $left;
    }

    private function expression(int $depth): BigRational
    {
        $left = $this->term($depth + 1);
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->tokens[$this->position++]; $right = $this->term($depth + 1);
            $left = $op === '+' ? $left->plus($right) : $left->minus($right);
        }
        return $left;
    }

    private function term(int $depth): BigRational
    {
        $left = $this->unary($depth + 1);
        while (in_array($this->peek(), ['*', '/'], true)) {
            $op = $this->tokens[$this->position++]; $right = $this->unary($depth + 1);
            $left = $op === '*' ? $left->multipliedBy($right) : $left->dividedBy($right);
        }
        return $left;
    }

    private function unary(int $depth): BigRational
    {
        if ($depth > 32) throw new InvalidArgumentException('Formula is nested too deeply.');
        if ($this->peek() === '-') { $this->position++; return $this->unary($depth + 1)->negated(); }
        $value = $this->atom($depth + 1);
        while ($this->peek() === '%') { $this->position++; $value = $value->dividedBy(100); }
        return $value;
    }

    private function atom(int $depth): BigRational
    {
        $this->tick();
        if ($depth > 32) throw new InvalidArgumentException('Formula is nested too deeply.');
        $token = $this->tokens[$this->position++] ?? null;
        if ($token === '(') { $value = $this->cond($depth + 1); $this->expect(')'); return $value; }
        if ($token !== null && preg_match('/^\d+(?:\.\d+)?$/D', $token)) return BigRational::of($token);
        if (in_array($token, self::FUNCTIONS, true) && $this->peek() === '(') {
            $this->position++; $args = [$this->cond($depth + 1)];
            while ($this->peek() === ',') { $this->position++; $args[] = $this->cond($depth + 1); }
            $this->expect(')');
            return $this->call($token, $args);
        }
        if (is_string($token) && !in_array($token, self::KEYWORDS, true) && array_key_exists($token, $this->variables)) {
            $value = $this->variables[$token];
            if (!is_string($value) || !preg_match('/^-?\d{1,18}(?:\.\d{1,6})?$/D', $value)) throw new InvalidArgumentException('Variables must be decimal strings.');
            return BigRational::of($value);
        }
        throw new InvalidArgumentException('Unknown variable or invalid formula token: '.($token ?? 'end'));
    }

    /** @param array<int,BigRational> $a */
    private function call(string $fn, array $a): BigRational
    {
        $n = count($a);
        $arity = function (int $min, ?int $max = null) use ($n, $fn) {
            if ($n < $min || $n > ($max ?? $min)) throw new InvalidArgumentException("$fn expects ".($max === null ? $min : "$min to $max").' arguments.');
        };
        switch ($fn) {
            case 'MIN': case 'MAX':
                $arity(2, 20); $best = $a[0];
                foreach ($a as $v) if ($fn === 'MIN' ? $v->isLessThan($best) : $v->isGreaterThan($best)) $best = $v;
                return $best;
            case 'ROUND': case 'ROUNDUP': case 'ROUNDDOWN':
                $arity(1, 2); $places = 0;
                if ($n === 2) {
                    if (!$a[1]->getDenominator()->isEqualTo(1)) throw new InvalidArgumentException("$fn places must be a whole number.");
                    $places = $a[1]->getNumerator()->toInt();
                }
                if ($places < 0 || $places > 6) throw new InvalidArgumentException("$fn places must be between 0 and 6.");
                $mode = ['ROUND' => RoundingMode::HALF_UP, 'ROUNDUP' => RoundingMode::UP, 'ROUNDDOWN' => RoundingMode::DOWN][$fn];
                return BigRational::of((string) $a[0]->toScale($places, $mode));
            case 'FLOOR': case 'CEIL':
                $arity(1);
                return BigRational::of((string) $a[0]->toScale(0, $fn === 'FLOOR' ? RoundingMode::FLOOR : RoundingMode::CEILING));
            case 'ABS': $arity(1); return $a[0]->abs();
            case 'IF': $arity(3); return $a[0]->isZero() ? $a[2] : $a[1];
            case 'CLAMP':
                $arity(3);
                if ($a[1]->isGreaterThan($a[2])) throw new InvalidArgumentException('CLAMP lower bound exceeds upper bound.');
                return $a[0]->isLessThan($a[1]) ? $a[1] : ($a[0]->isGreaterThan($a[2]) ? $a[2] : $a[0]);
        }
        throw new InvalidArgumentException('Unknown function '.$fn);
    }

    private function peek(): ?string { return $this->tokens[$this->position] ?? null; }
    private function expect(string $token): void
    {
        if (($this->tokens[$this->position++] ?? null) !== $token) throw new InvalidArgumentException('Expected '.$token.'.');
    }
}
