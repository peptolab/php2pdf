<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\HexString;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Document\PdfX;

use function array_filter;
use function array_values;
use function in_array;
use function is_array;
use function is_string;

/**
 * The optional content groups (layers) of imported pages, merged into the document's
 * /OCProperties so layers switched off in a source file stay off. Only groups the copied pages
 * refer to are carried over, with their default state, panel order, locks, radio-button groups
 * and automatic states from each source's default configuration.
 *
 * @internal
 */
final class OptionalContent
{
    private const int MAX_DEPTH = 32;

    /** @var list<Dictionary> */
    private array $auto = [];

    /** @var list<Reference> */
    private array $groups = [];

    private ?HiddenContent $removed = null;

    /** @var list<Reference> */
    private array $locked = [];

    /** @var list<Reference> */
    private array $off = [];

    /** @var list<mixed> */
    private array $order = [];

    /** @var list<list<Reference>> */
    private array $radio = [];

    /** Adds the groups of one source document that its copied pages use. */
    /** @param HiddenContent|null $removed the layers whose content was removed, left out here too */
    public function add(PdfReader $source, ObjectCopier $copier, ?HiddenContent $removed = null): void
    {
        $this->removed = $removed;
        $properties    = $source->dict($source->catalog(), 'OCProperties');
        if (null === $properties) {
            return;
        }
        $groups = $this->copied($source, $source->get($properties, 'OCGs'), $copier);
        if ([] === $groups) {
            return;
        }
        $this->merge($this->groups, $groups);
        $config = $source->dict($properties, 'D') ?? new Dictionary();
        $this->merge($this->off, $this->offGroups($source, $config, $groups, $copier));
        foreach ($this->order($source, $source->get($config, 'Order'), $copier, 0) as $item) {
            $this->order[] = $item;
        }
        $this->merge($this->locked, $this->copied($source, $source->get($config, 'Locked'), $copier));
        $this->radioGroups($source, $config, $copier);
        $this->autoStates($source, $config, $copier);
    }

    /**
     * Sets /OCProperties on the catalog when any imported page uses optional content. PDF/X-4
     * forbids automatic states (/AS), so they are left out there.
     */
    public function write(Dictionary $catalog, ?PdfX $pdfx): void
    {
        if ([] === $this->groups) {
            return;
        }
        $config = Dictionary::of([
            'Name'     => 'Default',
            'Order'    => [] === $this->order ? $this->groups : $this->order,
            'OFF'      => [] === $this->off ? null : $this->off,
            'Locked'   => [] === $this->locked ? null : $this->locked,
            'RBGroups' => [] === $this->radio ? null : $this->radio,
            'AS'       => null !== $pdfx || [] === $this->auto ? null : $this->auto,
        ]);
        $catalog->set('OCProperties', Dictionary::of(['OCGs' => $this->groups, 'D' => $config]));
    }

    private function autoStates(PdfReader $source, Dictionary $config, ObjectCopier $copier): void
    {
        $states = $source->get($config, 'AS');
        if (! is_array($states)) {
            return;
        }
        foreach ($states as $state) {
            $state = $source->resolve($state);
            if (! $state instanceof Dictionary) {
                continue;
            }
            $groups = $this->copied($source, $source->get($state, 'OCGs'), $copier);
            if ([] === $groups) {
                continue;
            }
            $this->auto[] = Dictionary::of([
                'Event'    => $source->get($state, 'Event'),
                'Category' => $source->get($state, 'Category'),
                'OCGs'     => $groups,
            ]);
        }
    }

    /**
     * The groups in a list that were copied into the document, as target references.
     *
     * @return list<Reference>
     */
    private function copied(PdfReader $source, mixed $list, ObjectCopier $copier): array
    {
        $list = $source->resolve($list);
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $target =
                $item instanceof Reference && true !== $this->removed?->isHidden($item)
                    ? $copier->copied($item)
                    : null;
            if (null !== $target && ! in_array($target, $out, strict: true)) {
                $out[] = $target;
            }
        }

        return $out;
    }

    /**
     * @param list<Reference> $into
     * @param list<Reference> $groups
     *
     * @param-out list<Reference> $into
     */
    private function merge(array &$into, array $groups): void
    {
        foreach ($groups as $group) {
            if (in_array($group, $into, strict: true)) {
                continue;
            }
            $into[] = $group;
        }
    }

    /**
     * The copied groups a configuration leaves off: those in /OFF, or, when the base state is
     * OFF, those not in /ON.
     *
     * @param list<Reference> $groups
     *
     * @return list<Reference>
     */
    private function offGroups(PdfReader $source, Dictionary $config, array $groups, ObjectCopier $copier): array
    {
        $base = $source->get($config, 'BaseState');
        if (! $base instanceof Name || 'OFF' !== $base->value) {
            return $this->copied($source, $source->get($config, 'OFF'), $copier);
        }
        $on = $this->copied($source, $source->get($config, 'ON'), $copier);

        return array_values(array_filter(
            $groups,
            static fn(Reference $group): bool => ! in_array($group, $on, strict: true),
        ));
    }

    /**
     * A panel order with groups that were not copied left out, and labelled sub-lists that are
     * left empty dropped.
     *
     * @return list<mixed>
     */
    private function order(PdfReader $source, mixed $order, ObjectCopier $copier, int $depth): array
    {
        $order = $source->resolve($order);
        if (! is_array($order) || $depth > self::MAX_DEPTH) {
            return [];
        }
        $out      = [];
        $hasGroup = false;
        foreach ($order as $item) {
            if ($item instanceof Reference) {
                $target = $copier->copied($item);
                if (null === $target) {
                    continue;
                }
                $out[]    = $target;
                $hasGroup = true;
                continue;
            }
            if (is_string($item) || $item instanceof HexString) {
                $out[] = $item;
                continue;
            }
            $nested = $this->order($source, $item, $copier, $depth + 1);
            if ([] !== $nested) {
                $out[]    = $nested;
                $hasGroup = true;
            }
        }

        return $hasGroup ? $out : [];
    }

    private function radioGroups(PdfReader $source, Dictionary $config, ObjectCopier $copier): void
    {
        $lists = $source->get($config, 'RBGroups');
        if (! is_array($lists)) {
            return;
        }
        foreach ($lists as $list) {
            $groups = $this->copied($source, $list, $copier);
            if ([] !== $groups) {
                $this->radio[] = $groups;
            }
        }
    }
}
