<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

use Composer\Repository\RepositoryInterface;
use Composer\Package\PackageInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Solver
{
    const RULE_INTERNAL_ALLOW_UPDATE = 1;
    const RULE_JOB_INSTALL = 2;
    const RULE_JOB_REMOVE = 3;
    const RULE_JOB_LOCK = 4;
    const RULE_NOT_INSTALLABLE = 5;
    const RULE_NOTHING_PROVIDES_DEP = 6;
    const RULE_PACKAGE_CONFLICT = 7;
    const RULE_PACKAGE_NOT_EXIST = 8;
    const RULE_PACKAGE_REQUIRES = 9;

    protected $policy;
    protected $pool;
    protected $installed;
    protected $rules;
    protected $updateAll;

    protected $ruleToJob = array();
    protected $addedMap = array();
    protected $fixMap = array();
    protected $updateMap = array();
    protected $watches = array();
    protected $removeWatches = array();

    protected $packageToUpdateRule = array();
    protected $packageToFeatureRule = array();

    public function __construct(PolicyInterface $policy, Pool $pool, RepositoryInterface $installed)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->installed = $installed;
        $this->rules = new RuleSet;
    }

    /**
     * Creates a new rule for the requirements of a package
     *
     * This rule is of the form (-A|B|C), where B and C are the providers of
     * one requirement of the package A.
     *
     * @param PackageInterface $package    The package with a requirement
     * @param array            $providers  The providers of the requirement
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the requirement name,
     *                                     that goes with the reason
     * @return Rule                        The generated rule or null if tautological
     */
    public function createRequireRule(PackageInterface $package, array $providers, $reason, $reasonData = null)
    {
        $literals = array(new Literal($package, false));

        foreach ($providers as $provider) {
            // self fulfilling rule?
            if ($provider === $package) {
                return null;
            }
            $literals[] = new Literal($provider, true);
        }

        return new Rule($literals, $reason, $reasonData);
    }

    /**
     * Create a new rule for updating a package
     *
     * If package A1 can be updated to A2 or A3 the rule is (A1|A2|A3).
     *
     * @param PackageInterface $package    The package to be updated
     * @param array            $updates    An array of update candidate packages
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule or null if tautology
     */
    protected function createUpdateRule(PackageInterface $package, array $updates, $reason, $reasonData = null)
    {
        $literals = array(new Literal($package, true));

        foreach ($updates as $update) {
            $literals[] = new Literal($update, true);
        }

        return new Rule($literals, $reason, $reasonData);
    }

    /**
     * Creates a new rule for installing a package
     *
     * The rule is simply (A) for a package A to be installed.
     *
     * @param PackageInterface $package    The package to be installed
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule
     */
    public function createInstallRule(PackageInterface $package, $reason, $reasonData = null)
    {
        return new Rule(new Literal($package, true));
    }

    /**
     * Creates a rule to install at least one of a set of packages
     *
     * The rule is (A|B|C) with A, B and C different packages. If the given
     * set of packages is empty an impossible rule is generated.
     *
     * @param array   $packages   The set of packages to choose from
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               The generated rule
     */
    public function createInstallOneOfRule(array $packages, $reason, $reasonData = null)
    {
        if (empty($packages)) {
            return $this->createImpossibleRule($reason, $reasonData);
        }

        $literals = array();
        foreach ($packages as $package) {
            $literals[] = new Literal($package, true);
        }

        return new Rule($literals, $reason, $reasonData);
    }

    /**
     * Creates a rule to remove a package
     *
     * The rule for a package A is (-A).
     *
     * @param PackageInterface $package    The package to be removed
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule
     */
    public function createRemoveRule(PackageInterface $package, $reason, $reasonData = null)
    {
        return new Rule(array(new Literal($package, false)), $reason, $reasonData);
    }

    /**
     * Creates a rule for two conflicting packages
     *
     * The rule for conflicting packages A and B is (-A|-B). A is called the issuer
     * and B the provider.
     *
     * @param PackageInterface $issuer     The package declaring the conflict
     * @param Package          $provider   The package causing the conflict
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule
     */
    public function createConflictRule(PackageInterface $issuer, Package $provider, $reason, $reasonData = null)
    {
        // ignore self conflict
        if ($issuer === $provider) {
            return null;
        }

        return new Rule(array(new Literal($issuer, false), new Literal($provider, false)), $reason, $reasonData);
    }

    /**
     * Intentionally creates a rule impossible to solve
     *
     * The rule is an empty one so it can never be satisfied.
     *
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               An empty rule
     */
    public function createImpossibleRule($reason, $reasonData = null)
    {
        return new Rule(array(), $reason, $reasonData);
    }

    /**
     * Adds a rule unless it duplicates an existing one of any type
     *
     * To be able to directly pass in the result of one of the rule creation
     * methods the rule may also be null to indicate that no rule should be
     * added.
     *
     * @param int  $type    A TYPE_* constant defining the rule type
     * @param Rule $newRule The rule about to be added
     */
    private function addRule($type, Rule $newRule = null) {
        if ($newRule) {
            foreach ($this->rules->getIterator() as $rule) {
                if ($rule->equals($newRule)) {
                    return;
                }
            }

            $this->rules->add($newRule, $type);
        }
    }

    public function addRulesForPackage(PackageInterface $package)
    {
        $workQueue = new \SPLQueue;
        $workQueue->enqueue($package);

        while (!$workQueue->isEmpty()) {
            $package = $workQueue->dequeue();
            if (isset($this->addedMap[$package->getId()])) {
                continue;
            }

            $this->addedMap[$package->getId()] = true;

            $dontFix = 0;
            if ($this->installed === $package->getRepository() && !isset($this->fixMap[$package->getId()])) {
                $dontFix = 1;
            }

            if (!$dontFix && !$this->policy->installable($this, $this->pool, $this->installed, $package)) {
                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRemoveRule($package, self::RULE_NOT_INSTALLABLE, (string) $package));
                continue;
            }

            foreach ($package->getRequires() as $link) {
                $possibleRequires = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                // the strategy here is to not insist on dependencies
                // that are already broken. so if we find one provider
                // that was already installed, we know that the
                // dependency was not broken before so we enforce it
                if ($dontFix) {
                    $foundInstalled = false;
                    foreach ($possibleRequires as $require) {
                        if ($this->installed === $require->getRepository()) {
                            $foundInstalled = true;
                            break;
                        }
                    }

                    // no installed provider found: previously broken dependency => don't add rule
                    if (!$foundInstalled) {
                        continue;
                    }
                }

                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package, $possibleRequires, self::RULE_PACKAGE_REQUIRES, (string) $link));

                foreach ($possibleRequires as $require) {
                    $workQueue->enqueue($require);
                }
            }

            foreach ($package->getConflicts() as $link) {
                $possibleConflicts = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                foreach ($possibleConflicts as $conflict) {
                    if ($dontfix && $this->installed === $conflict->getRepository()) {
                        continue;
                    }

                    $this->addRule(RuleSet::TYPE_PACKAGE, $this->createConflictRule($package, $conflict, self::RULE_PACKAGE_CONFLICT, (string) $link));
                }
            }

            foreach ($package->getRecommends() as $link) {
                foreach ($this->pool->whatProvides($link->getTarget(), $link->getConstraint()) as $recommend) {
                    $workQueue->enqueue($recommend);
                }
            }

            foreach ($package->getSuggests() as $link) {
                foreach ($this->pool->whatProvides($link->getTarget(), $link->getConstraint()) as $suggest) {
                    $workQueue->enqueue($suggest);
                }
            }
        }
    }

    /**
     * Adds all rules for all update packages of a given package
     *
     * @param PackageInterface $package  Rules for this package's updates are to
     *                                   be added
     * @param bool             $allowAll Whether downgrades are allowed
     */
    private function addRulesForUpdatePackages(PackageInterface $package, $allowAll)
    {
        $updates = $this->policy->findUpdatePackages($this, $this->pool, $this->installed, $package, $allowAll);

        $this->addRulesForPackage($package);

        foreach ($updates as $update) {
            $this->addRulesForPackage($update);
        }
    }

    /**
     * Alters watch chains for a rule.
     *
     * Next1/2 always points to the next rule that is watching the same package.
     * The watches array contains rules to start from for each package
     *
     */
    private function addWatchesToRule(Rule $rule)
    {
        // skip simple assertions of the form (A) or (-A)
        if ($rule->isAssertion()) {
            return;
        }

        if (!isset($this->watches[$rule->watch1])) {
            $this->watches[$rule->watch1] = null;
        }

        $rule->next1 = $this->watches[$rule->watch1];
        $this->watches[$rule->watch1] = $rule;

        if (!isset($this->watches[$rule->watch2])) {
            $this->watches[$rule->watch2] = null;
        }

        $rule->next2 = $this->watches[$rule->watch2];
        $this->watches[$rule->watch2] = $rule;
    }

    /**
     * Put watch2 on rule's literal with highest level
     */
    private function watch2OnHighest(Rule $rule)
    {
        $literals = $rule->getLiterals();

        // if there are only 2 elements, both are being watched anyway
        if ($literals < 3) {
            return;
        }

        $watchLevel = 0;

        foreach ($literals as $literal) {
            $level = $this->decisionsMap[$literal->getPackageId()];
            if ($level < 0) {
                $level = -$level;
            }

            if ($level > $watchLevel) {
                $rule->watch2 = $literal->getId();
                $watchLevel = $level;
            }
        }
    }

    private function findDecisionRule(PackageInterface $package)
    {
        foreach ($this->decisionQueue as $i => $literal) {
            if ($package === $literal->getPackage()) {
                return $this->decisionQueueWhy[$i];
            }
        }

        return null;
    }

    // aka solver_makeruledecisions
    private function makeAssertionRuleDecisions()
    {
        // do we need to decide a SYSTEMSOLVABLE at level 1?

        $decisionStart = count($this->decisionQueue);

        for ($ruleIndex = 0; $ruleIndex < count($this->rules); $ruleIndex++) {
            $rule = $this->rules->ruleById($ruleIndex);

            if ($rule->isWeak() || !$rule->isAssertion() || $rule->isDisabled()) {
                continue;
            }

            $literals = $rule->getLiterals();
            $literal = $literals[0];

            if (!$this->decided($literal->getPackage())) {
                $this->decisionQueue[] = $literal;
                $this->decisionQueueWhy[] = $rule;
                $this->addDecision($literal, 1);
                continue;
            }

            if ($this->decisionsSatisfy($literal)) {
                continue;
            }

            // found a conflict
            if (RuleSet::TYPE_LEARNED === $rule->getType()) {
                $rule->disable();
                continue;
            }

            $conflict = $this->findDecisionRule($literal->getPackage());
            /** TODO: handle conflict with systemsolvable? */

            $this->learnedPool[] = array($rule, $conflict);

            if ($conflict && RuleSet::TYPE_PACKAGE === $conflict->getType()) {

                if ($rule->getType() == RuleSet::TYPE_JOB) {
                    $why = $this->ruleToJob[$rule->getId()];
                } else {
                    $why = $rule;
                }
                $this->problems[] = array($why);

                $this->disableProblem($why);
                continue;
            }

            // conflict with another job or update/feature rule

            $this->problems[] = array();

            // push all of our rules (can only be feature or job rules)
            // asserting this literal on the problem stack
            foreach ($this->rules->getIteratorFor(array(RuleSet::TYPE_JOB, RuleSet::TYPE_UPDATE, RuleSet::TYPE_FEATURE)) as $assertRule) {
                if ($assertRule->isDisabled() || !$assertRule->isAssertion() || $assertRule->isWeak()) {
                    continue;
                }

                $assertRuleLiterals = $assertRule->getLiterals();
                $assertRuleLiteral = $assertRuleLiterals[0];

                if  ($literal->getPackageId() !== $assertRuleLiteral->getPackageId()) {
                    continue;
                }

                if ($assertRule->getType() === RuleSet::TYPE_JOB) {
                    $why = $this->ruleToJob[$assertRule->getId()];
                } else {
                    $why = $assertRule;
                }
                $this->problems[count($this->problems) - 1][] = $why;

                $this->disableProblem($why);
            }

            // start over
            while (count($this->decisionQueue) > $decisionStart) {
                $decisionLiteral = array_pop($this->decisionQueue);
                array_pop($this->decisionQueueWhy);
                unset($this->decisionQueueFree[count($this->decisionQueue)]);
                unset($this->decisionMap[$decisionLiteral->getPackageId()]);
            }
            $ruleIndex = -1;
        }

        foreach ($this->rules as $rule) {
            if (!$rule->isWeak() || !$rule->isAssertion() || $rule->isDisabled()) {
                continue;
            }

            $literals = $rule->getLiterals();
            $literal = $literals[0];

            if (!isset($this->decisionMap[$literal->getPackageId()])) {
                $this->decisionQueue[] = $literal;
                $this->decisionQueueWhy[] = $rule;
                $this->addDecision($literal, 1);
                continue;
            }

            if ($this->decisionsSatisfy($literals[0])) {
                continue;
            }

            // conflict, but this is a weak rule => disable
            if ($rule->getType() == RuleSet::TYPE_JOB) {
                $why = $this->ruleToJob[$rule->getId()];
            } else {
                $why = $rule;
            }

            $this->disableProblem($why);
            /** TODO solver_reenablepolicyrules(solv, -(v + 1)); */
        }
    }

    public function addChoiceRules()
    {

// void
// solver_addchoicerules(Solver *solv)
// {
//   Pool *pool = solv->pool;
//   Map m, mneg;
//   Rule *r;
//   Queue q, qi;
//   int i, j, rid, havechoice;
//   Id p, d, *pp;
//   Id p2, pp2;
//   Solvable *s, *s2;
//
//   solv->choicerules = solv->nrules;
//   if (!pool->installed)
//     {
//       solv->choicerules_end = solv->nrules;
//       return;
//     }
//   solv->choicerules_ref = sat_calloc(solv->rpmrules_end, sizeof(Id));
//   queue_init(&q);
//   queue_init(&qi);
//   map_init(&m, pool->nsolvables);
//   map_init(&mneg, pool->nsolvables);
//   /* set up negative assertion map from infarch and dup rules */
//   for (rid = solv->infarchrules, r = solv->rules + rid; rid < solv->infarchrules_end; rid++, r++)
//     if (r->p < 0 && !r->w2 && (r->d == 0 || r->d == -1))
//       MAPSET(&mneg, -r->p);
//   for (rid = solv->duprules, r = solv->rules + rid; rid < solv->duprules_end; rid++, r++)
//     if (r->p < 0 && !r->w2 && (r->d == 0 || r->d == -1))
//       MAPSET(&mneg, -r->p);
//   for (rid = 1; rid < solv->rpmrules_end ; rid++)
//     {
//       r = solv->rules + rid;
//       if (r->p >= 0 || ((r->d == 0 || r->d == -1) && r->w2 < 0))
//     continue;   /* only look at requires rules */
//       // solver_printrule(solv, SAT_DEBUG_RESULT, r);
//       queue_empty(&q);
//       queue_empty(&qi);
//       havechoice = 0;
//       FOR_RULELITERALS(p, pp, r)
//     {
//       if (p < 0)
//         continue;
//       s = pool->solvables + p;
//       if (!s->repo)
//         continue;
//       if (s->repo == pool->installed)
//         {
//           queue_push(&q, p);
//           continue;
//         }
//       /* check if this package is "blocked" by a installed package */
//       s2 = 0;
//       FOR_PROVIDES(p2, pp2, s->name)
//         {
//           s2 = pool->solvables + p2;
//           if (s2->repo != pool->installed)
//         continue;
//           if (!pool->implicitobsoleteusesprovides && s->name != s2->name)
//             continue;
//           if (pool->obsoleteusescolors && !pool_colormatch(pool, s, s2))
//             continue;
//           break;
//         }
//       if (p2)
//         {
//           /* found installed package p2 that we can update to p */
//           if (MAPTST(&mneg, p))
//         continue;
//           if (policy_is_illegal(solv, s2, s, 0))
//         continue;
//           queue_push(&qi, p2);
//           queue_push(&q, p);
//           continue;
//         }
//       if (s->obsoletes)
//         {
//           Id obs, *obsp = s->repo->idarraydata + s->obsoletes;
//           s2 = 0;
//           while ((obs = *obsp++) != 0)
//         {
//           FOR_PROVIDES(p2, pp2, obs)
//             {
//               s2 = pool->solvables + p2;
//               if (s2->repo != pool->installed)
//             continue;
//               if (!pool->obsoleteusesprovides && !pool_match_nevr(pool, pool->solvables + p2, obs))
//             continue;
//               if (pool->obsoleteusescolors && !pool_colormatch(pool, s, s2))
//             continue;
//               break;
//             }
//           if (p2)
//             break;
//         }
//           if (obs)
//         {
//           /* found installed package p2 that we can update to p */
//           if (MAPTST(&mneg, p))
//             continue;
//           if (policy_is_illegal(solv, s2, s, 0))
//             continue;
//           queue_push(&qi, p2);
//           queue_push(&q, p);
//           continue;
//         }
//         }
//       /* package p is independent of the installed ones */
//       havechoice = 1;
//     }
//       if (!havechoice || !q.count)
//     continue;   /* no choice */
//
//       /* now check the update rules of the installed package.
//        * if all packages of the update rules are contained in
//        * the dependency rules, there's no need to set up the choice rule */
//       map_empty(&m);
//       FOR_RULELITERALS(p, pp, r)
//         if (p > 0)
//       MAPSET(&m, p);
//       for (i = 0; i < qi.count; i++)
//     {
//       if (!qi.elements[i])
//         continue;
//       Rule *ur = solv->rules + solv->updaterules + (qi.elements[i] - pool->installed->start);
//       if (!ur->p)
//         ur = solv->rules + solv->featurerules + (qi.elements[i] - pool->installed->start);
//       if (!ur->p)
//         continue;
//       FOR_RULELITERALS(p, pp, ur)
//         if (!MAPTST(&m, p))
//           break;
//       if (p)
//         break;
//       for (j = i + 1; j < qi.count; j++)
//         if (qi.elements[i] == qi.elements[j])
//           qi.elements[j] = 0;
//     }
//       if (i == qi.count)
//     {
// #if 0
//       printf("skipping choice ");
//       solver_printrule(solv, SAT_DEBUG_RESULT, solv->rules + rid);
// #endif
//       continue;
//     }
//       d = q.count ? pool_queuetowhatprovides(pool, &q) : 0;
//       solver_addrule(solv, r->p, d);
//       queue_push(&solv->weakruleq, solv->nrules - 1);
//       solv->choicerules_ref[solv->nrules - 1 - solv->choicerules] = rid;
// #if 0
//       printf("OLD ");
//       solver_printrule(solv, SAT_DEBUG_RESULT, solv->rules + rid);
//       printf("WEAK CHOICE ");
//       solver_printrule(solv, SAT_DEBUG_RESULT, solv->rules + solv->nrules - 1);
// #endif
//     }
//   queue_free(&q);
//   queue_free(&qi);
//   map_free(&m);
//   map_free(&mneg);
//   solv->choicerules_end = solv->nrules;
// }
    }

/***********************************************************************
 ***
 ***  Policy rule disabling/reenabling
 ***
 ***  Disable all policy rules that conflict with our jobs. If a job
 ***  gets disabled later on, reenable the involved policy rules again.
 ***
 *** /

#define DISABLE_UPDATE  1
#define DISABLE_INFARCH 2
#define DISABLE_DUP 3
*/
    protected function jobToDisableQueue(array $job, array $disableQueue)
    {
        switch ($job['cmd']) {
            case 'install':
                foreach ($job['packages'] as $package) {
                    if ($this->installed === $package->getRepository()) {
                        $disableQueue[] = array('type' => 'update', 'package' => $package);
                    }
                }
            break;

            case 'remove':
                foreach ($job['packages'] as $package) {
                    if ($this->installed === $package->getRepository()) {
                        $disableQueue[] = array('type' => 'update', 'package' => $package);
                    }
                }
            break;
        }

        return $disableQueue;
    }

    protected function disableUpdateRule($package)
    {
        // find update & feature rule and disable
        if (isset($this->packageToUpdateRule[$package->getId()])) {
            $this->packageToUpdateRule[$package->getId()]->disable();
        }

        if (isset($this->packageToFeatureRule[$package->getId()])) {
            $this->packageToFeatureRule[$literal->getPackageId()]->disable();
        }
    }

    /**
    * Disables all policy rules that conflict with jobs
    */
    protected function disablePolicyRules()
    {
        $lastJob = null;
        $allQueue = array();

        $iterator = $this->rules->getIteratorFor(RuleSet::TYPE_JOB);
        foreach ($iterator as $rule) {
            if ($rule->isDisabled()) {
                continue;
            }

            $job = $this->ruleToJob[$rule->getId()];

            if ($job === $lastJob) {
                continue;
            }

            $lastJob = $job;

            $allQueue = $this->jobToDisableQueue($job, $allQueue);
        }

        foreach ($allQueue as $disable) {
            switch ($disable['type']) {
                case 'update':
                    $this->disableUpdateRule($disable['package']);
                break;
                default:
                    throw new \RuntimeException("Unsupported disable type: " . $disable['type']);
            }
        }
    }

    public function solve(Request $request)
    {
        $this->jobs = $request->getJobs();
        $installedPackages = $this->installed->getPackages();

        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'update-all':
                    foreach ($installedPackages as $package) {
                        $this->updateMap[$package->getId()] = true;
                    }
                break;

                case 'fix-all':
                    foreach ($installedPackages as $package) {
                        $this->fixMap[$package->getId()] = true;
                    }
                break;
            }

            foreach ($job['packages'] as $package) {
                switch ($job['cmd']) {
                    case 'fix':
                        if ($this->installed === $package->getRepository()) {
                            $this->fixMap[$package->getId()] = true;
                        }
                        break;
                    case 'update':
                        if ($this->installed === $package->getRepository()) {
                            $this->updateMap[$package->getId()] = true;
                        }
                        break;
                }
            }
        }

        foreach ($installedPackages as $package) {
            $this->addRulesForPackage($package);
        }

        foreach ($installedPackages as $package) {
            $this->addRulesForUpdatePackages($package, true);
        }


        foreach ($this->jobs as $job) {
            foreach ($job['packages'] as $package) {
                switch ($job['cmd']) {
                    case 'install':
                        $this->installCandidateMap[$package->getId()] = true;
                        $this->addRulesForPackage($package);
                    break;
                }
            }
        }

        // solver_addrpmrulesforweak(solv, &addedmap);

        foreach ($installedPackages as $package) {
            // create a feature rule which allows downgrades
            $updates = $this->policy->findUpdatePackages($this, $this->pool, $this->installed, $package, true);
            $featureRule = $this->createUpdateRule($package, $updates, self::RULE_INTERNAL_ALLOW_UPDATE, (string) $package);

            // create an update rule which does not allow downgrades
            $updates = $this->policy->findUpdatePackages($this, $this->pool, $this->installed, $package, false);
            $rule = $this->createUpdateRule($package, $updates, self::RULE_INTERNAL_ALLOW_UPDATE, (string) $package);

            if ($rule->equals($featureRule)) {
                if ($this->policy->allowUninstall()) {
                    $featureRule->setWeak(true);
                    $this->addRule(RuleSet::TYPE_FEATURE, $featureRule);
                    $this->packageToFeatureRule[$package->getId()] = $rule;
                } else {
                    $this->addRule(RuleSet::TYPE_UPDATE, $rule);
                    $this->packageToUpdateRule[$package->getId()] = $rule;
                }
            } else if ($this->policy->allowUninstall()) {
                $featureRule->setWeak(true);
                $rule->setWeak(true);

                $this->addRule(RuleSet::TYPE_FEATURE, $featureRule);
                $this->addRule(RuleSet::TYPE_UPDATE, $rule);

                $this->packageToFeatureRule[$package->getId()] = $rule;
                $this->packageToUpdateRule[$package->getId()] = $rule;
            }
        }

        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'install':
                    $rule = $this->createInstallOneOfRule($job['packages'], self::RULE_JOB_INSTALL, $job['packageName']);
                    $this->addRule(RuleSet::TYPE_JOB, $rule);
                    $this->ruleToJob[$rule->getId()] = $job;
                    break;
                case 'remove':
                    // remove all packages with this name including uninstalled
                    // ones to make sure none of them are picked as replacements

                    // todo: cleandeps
                    foreach ($job['packages'] as $package) {
                        $rule = $this->createRemoveRule($package, self::RULE_JOB_REMOVE);
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
                        $this->ruleToJob[$rule->getId()] = $job;
                    }
                    break;
                case 'lock':
                    foreach ($job['packages'] as $package) {
                        if ($this->installed === $package->getRepository()) {
                            $rule = $this->createInstallRule($package, self::RULE_JOB_LOCK);
                        } else {
                            $rule = $this->createRemoveRule($package, self::RULE_JOB_LOCK);
                        }
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
                        $this->ruleToJob[$rule->getId()] = $job;
                    }
                break;
            }
        }

        $this->addChoiceRules();

        foreach ($this->rules as $rule) {
            $this->addWatchesToRule($rule);
        }

        /* disable update rules that conflict with our job */
        $this->disablePolicyRules();

        /* make decisions based on job/update assertions */
        $this->makeAssertionRuleDecisions();

        $installRecommended = 0;
        $this->runSat(true, $installRecommended);
        //$this->printDecisionMap();
        //findrecommendedsuggested(solv);
        //solver_prepare_solutions(solv);

        $transaction = array();

        foreach ($this->decisionQueue as $literal) {
            $package = $literal->getPackage();

            // wanted & installed || !wanted & !installed
            if ($literal->isWanted() == ($this->installed == $package->getRepository())) {
                continue;
            }

            $transaction[] = array(
                'job' => ($literal->isWanted()) ? 'install' : 'remove',
                'package' => $package,
            );
        }

        return array_reverse($transaction);
    }

    protected $decisionQueue = array();
    protected $decisionQueueWhy = array();
    protected $decisionQueueFree = array();
    protected $propagateIndex;
    protected $decisionMap = array();
    protected $branches = array();
    protected $problems = array();
    protected $learnedPool = array();

    protected function literalFromId($id)
    {
        $package = $this->pool->packageById($id);
        return new Literal($package, $id > 0);
    }

    protected function addDecision(Literal $l, $level)
    {
        if ($l->isWanted()) {
            $this->decisionMap[$l->getPackageId()] = $level;
        } else {
            $this->decisionMap[$l->getPackageId()] = -$level;
        }
    }

    protected function addDecisionId($literalId, $level)
    {
        $packageId = abs($literalId);
        if ($literalId > 0) {
            $this->decisionMap[$packageId] = $level;
        } else {
            $this->decisionMap[$packageId] = -$level;
        }
    }

    protected function decisionsContain(Literal $l)
    {
        return (isset($this->decisionMap[$l->getPackageId()]) && (
            $this->decisionMap[$l->getPackageId()] > 0 && $l->isWanted() ||
            $this->decisionMap[$l->getPackageId()] < 0 && !$l->isWanted()
        ));
    }

    protected function decisionsContainId($literalId)
    {
        $packageId = abs($literalId);
        return (isset($this->decisionMap[$packageId]) && (
            $this->decisionMap[$packageId] > 0 && $literalId > 0 ||
            $this->decisionMap[$packageId] < 0 && $literalId < 0
        ));
    }

    protected function decisionsSatisfy(Literal $l)
    {
        return ($l->isWanted() && isset($this->decisionMap[$l->getPackageId()]) && $this->decisionMap[$l->getPackageId()] > 0) ||
            (!$l->isWanted() && (!isset($this->decisionMap[$l->getPackageId()]) || $this->decisionMap[$l->getPackageId()] < 0));
    }

    protected function decisionsConflict(Literal $l)
    {
        return (isset($this->decisionMap[$l->getPackageId()]) && (
            $this->decisionMap[$l->getPackageId()] > 0 && !$l->isWanted() ||
            $this->decisionMap[$l->getPackageId()] < 0 && $l->isWanted()
        ));
    }

    protected function decisionsConflictId($literalId)
    {
        $packageId = abs($literalId);
        return (isset($this->decisionMap[$packageId]) && (
            $this->decisionMap[$packageId] > 0 && !$literalId < 0 ||
            $this->decisionMap[$packageId] < 0 && $literalId > 0
        ));
    }

    protected function decided(PackageInterface $p)
    {
        return isset($this->decisionMap[$p->getId()]);
    }

    protected function undecided(PackageInterface $p)
    {
        return !isset($this->decisionMap[$p->getId()]);
    }

    protected function decidedInstall(PackageInterface $p) {
        return isset($this->decisionMap[$p->getId()]) && $this->decisionMap[$p->getId()] > 0;
    }

    protected function decidedRemove(PackageInterface $p) {
        return isset($this->decisionMap[$p->getId()]) && $this->decisionMap[$p->getId()] < 0;
    }

    /**
     * Makes a decision and propagates it to all rules.
     *
     * Evaluates each term affected by the decision (linked through watches)
     * If we find unit rules we make new decisions based on them
     *
     * @return Rule|null A rule on conflict, otherwise null.
     */
    protected function propagate($level)
    {
        while ($this->propagateIndex < count($this->decisionQueue)) {
            // we invert the decided literal here, example:
            // A was decided => (-A|B) now requires B to be true, so we look for
            // rules which are fulfilled by -A, rather than A.

            $literal = $this->decisionQueue[$this->propagateIndex]->inverted();

            $this->propagateIndex++;

            // /* foreach rule where 'pkg' is now FALSE */
            //for (rp = watches + pkg; *rp; rp = next_rp)
            if (!isset($this->watches[$literal->getId()])) {
                continue;
            }

            for ($rule = $this->watches[$literal->getId()]; $rule !== null; $rule = $nextRule) {
                $nextRule = $rule->getNext($literal);

                if ($rule->isDisabled()) {
                    continue;
                }

                $otherWatch = $rule->getOtherWatch($literal);

                if ($this->decisionsContainId($otherWatch)) {
                    continue;
                }

                $ruleLiterals = $rule->getLiterals();

                if (sizeof($ruleLiterals) > 2) {
                    foreach ($ruleLiterals as $ruleLiteral) {
                        if (!$otherWatch->equals($ruleLiteral) &&
                            !$this->decisionsConflict($ruleLiteral)) {


                            if ($literal->equals($rule->getWatch1())) {
                                $rule->setWatch1($ruleLiteral);
                                $rule->setNext1($rule);
                            } else {
                                $rule->setWatch2($ruleLiteral);
                                $rule->setNext2($rule);
                            }

                            $this->watches[$ruleLiteral->getId()] = $rule;
                            continue 2;
                        }
                    }
                }

                // yay, we found a unit clause! try setting it to true
                if ($this->decisionsConflictId($otherWatch)) {
                    return $rule;
                }

                $this->addDecisionId($otherWatch, $level);

                $this->decisionQueue[] = $this->literalFromId($otherWatch);
                $this->decisionQueueWhy[] = $rule;
            }
        }

        return null;
    }

    /**-------------------------------------------------------------------
     *
     * setpropagatelearn
     *
     * add free decision (solvable to install) to decisionq
     * increase level and propagate decision
     * return if no conflict.
     *
     * in conflict case, analyze conflict rule, add resulting
     * rule to learnt rule set, make decision from learnt
     * rule (always unit) and re-propagate.
     *
     * returns the new solver level or 0 if unsolvable
     *
     */
    private function setPropagateLearn($level, Literal $literal, $disableRules, Rule $rule)
    {
        assert($rule != null);
        assert($literal != null);

        $level++;

        $this->addDecision($literal, $level);
        $this->decisionQueue[] = $literal;
        $this->decisionQueueWhy[] = $rule;
        $this->decisionQueueFree[count($this->decisionQueueWhy) - 1] = true;

        while (true) {
            $rule = $this->propagate($level);

            if (!$rule) {
                break;
            }

            if ($level == 1) {
                return $this->analyze_unsolvable($rule, $disableRules);
            }

            // conflict
            $learnedRule = null;
            $why = null;
            $newLevel = $this->analyze($level, $rule, $learnedRule, $why);

            assert($newLevel > 0);
            assert($newLevel < $level);

            $level = $newLevel;

            $this->revert($level);

            assert($newRule != null);
            $this->addRule(RuleSet::TYPE_LEARNED, $newRule);

            $this->learnedWhy[] = $why;

            $this->watch2OnHighest($newRule);
            $this->addWatchesToRule($newRule);

            $literals = $newRule->getLiterals();
            $this->addDecision($literals[0], $level);
            $this->decisionQueue[] = $literals[0];
            $this->decisionQueueWhy[] = $newRule;
        }

        return $level;
    }

    private function selectAndInstall($level, array $decisionQueue, $disableRules, Rule $rule)
    {
        // choose best package to install from decisionQueue
        $literals = $this->policy->selectPreferedPackages($this, $this->pool, $this->installed, $decisionQueue);

        // if there are multiple candidates, then branch
        if (count($literals) > 1) {
            foreach ($literals as $i => $literal) {
                if (0 !== $i) {
                    $this->branches[] = array($literal, $level);
                }
            }
        }

        return $this->setPropagateLearn($level, $literals[0], $disableRules, $rule);
    }

    private function analyzeUnsolvableRule($conflictRule, &$lastWeakWhy)
    {
        $why = $conflictRule->getId();

        if ($conflictRule->getType() == RuleSet::TYPE_LEARNED) {
            throw new \RuntimeException("handling conflicts with learned rules unimplemented");
                /** TODO:
      for (i = solv->learnt_why.elements[why - solv->learntrules]; solv->learnt_pool.elements[i]; i++)
    if (solv->learnt_pool.elements[i] > 0)
      analyze_unsolvable_rule(solv, solv->rules + solv->learnt_pool.elements[i], lastweakp);
      return;
*/
        }

        if ($conflictRule->getType() == RuleSet::TYPE_PACKAGE) {
            // package rules cannot be part of a problem
            return;
        }

        if ($conflictRule->isWeak()) {
            /** TODO why > or < lastWeakProblem? */
            if (!$lastWeakWhy || $why > $lastWeakWhy) {
                $lastWeakProblem = $why;
            }
        }

        if ($conflictRule->getType() == RuleSet::TYPE_JOB) {
            $why = $this->ruleToJob[$conflictRule->getId()];
        }

        // if this problem was already found skip it
        if (in_array($why, $this->problems[count($this->problems) - 1], true)) {
            return;
        }

        $this->problems[count($this->problems) - 1][] = $why;
    }

    private function analyzeUnsolvable($conflictRule, $disableRules)
    {
        $lastWeakWhy = null;
        $this->problems[] = array();
        $this->learnedPool[] = array($conflictRule);

        $this->analyzeUnsolvableRule($conflictRule, $lastWeakWhy);

        $seen = array();
        $literals = $conflictRule->getLiterals();

/* unecessary because unlike rule.d, watch2 == 2nd literal, unless watch2 changed
        if (sizeof($literals) == 2) {
            $literals[1] = $this->literalFromId($conflictRule->watch2);
        }
*/

        foreach ($literals as $literal) {
            // skip the one true literal
            if ($this->decisionsSatisfy($literal)) {
                continue;
            }
            $seen[$literal->getPackageId()] = true;
        }

        $decisionId = count($this->decisionQueue);

        while ($decisionId > 0) {
            $decisionId--;

            $literal = $this->decisionQueue[$decisionId];

            // skip literals that are not in this rule
            if (!isset($seen[$literal->getPackageId()])) {
                continue;
            }

            $why = $this->decisionQueueWhy[$decisionId];
            $this->learnedPool[count($this->learnedPool) - 1][] = $why;

            $this->analyzeUnsolvableRule($why, $lastWeakWhy);

            $literals = $why->getLiterals();
/* unecessary because unlike rule.d, watch2 == 2nd literal, unless watch2 changed
            if (sizeof($literals) == 2) {
                $literals[1] = $this->literalFromId($why->watch2);
            }
*/

            foreach ($literals as $literal) {
                // skip the one true literal
                if ($this->decisionsSatisfy($literal)) {
                    continue;
                }
                $seen[$literal->getPackageId()] = true;
            }
        }

        if ($lastWeakWhy) {
            array_pop($this->problems);
            array_pop($this->learnedPool);

            if ($lastWeakWhy->getType() === RuleSet::TYPE_JOB) {
                $why = $this->ruleToJob[$lastWeakWhy];
            } else {
                $why = $lastWeakWhy;
            }

            if ($lastWeakWhy->getType() == RuleSet::TYPE_CHOICE) {
                $this->disableChoiceRules($lastWeakWhy);
            }

            $this->disableProblem($why);

            /**
@TODO what does v < 0 mean here? ($why == v)
      if (v < 0)
    solver_reenablepolicyrules(solv, -(v + 1));
*/
            $this->resetSolver();

            return true;
        }

        if ($disableRules) {
            foreach ($this->problems[count($this->problems) - 1] as $why) {
                $this->disableProblem($why);
            }

            $this->resetSolver();
            return true;
        }

        return false;
    }

    private function disableProblem($why)
    {
        if ($why instanceof Rule) {
            $why->disable();
        } else if (is_array($why)) {

            // disable all rules of this job
            foreach ($this->ruleToJob as $ruleId => $job) {
                if ($why === $job) {
                    $this->rules->ruleById($ruleId)->disable();
                }
            }
        }
    }

    private function resetSolver()
    {
        while ($literal = array_pop($this->decisionQueue)) {
            if (isset($this->decisionMap[$literal->getPackageId()])) {
                unset($this->decisionMap[$literal->getPackageId()]);
            }
        }

        $this->decisionQueueWhy = array();
        $this->decisionQueueFree = array();
        $this->recommendsIndex = -1;
        $this->propagateIndex = 0;
        $this->recommendations = array();
        $this->branches = array();

        $this->enableDisableLearnedRules();
        $this->makeAssertionRuleDecisions();
    }

    /*-------------------------------------------------------------------
    * enable/disable learnt rules
    *
    * we have enabled or disabled some of our rules. We now reenable all
    * of our learnt rules except the ones that were learnt from rules that
    * are now disabled.
    */
    private function enableDisableLearnedRules()
    {
        foreach ($this->rules->getIteratorFor(RuleSet::TYPE_LEARNED) as $rule) {
            $why = $this->learnedWhy[$rule->getId()];
            $problem = $this->learnedPool[$why];

            $foundDisabled = false;
            foreach ($problem as $problemRule) {
                if ($problemRule->disabled()) {
                    $foundDisabled = true;
                    break;
                }
            }

            if ($foundDisabled && $rule->isEnabled()) {
                $rule->disable();
            } else if (!$foundDisabled && $rule->isDisabled()) {
                $rule->enable();
            }
        }
    }

    private function runSat($disableRules = true, $installRecommended = false)
    {
        $this->propagateIndex = 0;

        //   /*
        //    * here's the main loop:
        //    * 1) propagate new decisions (only needed once)
        //    * 2) fulfill jobs
        //    * 3) try to keep installed packages
        //    * 4) fulfill all unresolved rules
        //    * 5) install recommended packages
        //    * 6) minimalize solution if we had choices
        //    * if we encounter a problem, we rewind to a safe level and restart
        //    * with step 1
        //    */

        $decisionQueue = array();
        $decisionSupplementQueue = array();
        $disableRules = array();

        $level = 1;
        $systemLevel = $level + 1;
        $minimizationsteps = 0;
        $installedPos = 0;

        $this->installedPackages = $this->installed->getPackages();

        while (true) {

            $conflictRule = $this->propagate($level);
            if ($conflictRule !== null) {
                if ($this->analyzeUnsolvable($conflictRule, $disableRules)) {
                    continue;
                } else {
                    return;
                }
            }

            // handle job rules
            if ($level < $systemLevel) {
                $iterator = $this->rules->getIteratorFor(RuleSet::TYPE_JOB);
                foreach ($iterator as $rule) {
                    if ($rule->isEnabled()) {
                        $decisionQueue = array();
                        $noneSatisfied = true;

                        foreach ($rule->getLiterals() as $literal) {
                            if ($this->decisionsSatisfy($literal)) {
                                $noneSatisfied = false;
                                break;
                            }
                            $decisionQueue[] = $literal;
                        }

                        if ($noneSatisfied && count($decisionQueue)) {
                            // prune all update packages until installed version
                            // except for requested updates
                            if (count($this->installed) != count($this->updateMap)) {
                                $prunedQueue = array();
                                foreach ($decisionQueue as $literal) {
                                    if ($this->installed === $literal->getPackage()->getRepository()) {
                                        $prunedQueue[] = $literal;
                                        if (isset($this->updateMap[$literal->getPackageId()])) {
                                            $prunedQueue = $decisionQueue;
                                            break;
                                        }
                                    }
                                }
                                $decisionQueue = $prunedQueue;
                            }
                        }

                        if ($noneSatisfied && count($decisionQueue)) {

                            $oLevel = $level;
                            $level = $this->selectAndInstall($level, $decisionQueue, $disableRules, $rule);

                            if (0 === $level) {
                                return;
                            }
                            if ($level <= $oLevel) {
                                break;
                            }
                        }
                    }
                }

                $systemLevel = $level + 1;

                // jobs left
                $iterator->next();
                if ($iterator->valid()) {
                    continue;
                }
            }

            // handle installed packages
            if ($level < $systemLevel) {
                // use two passes if any packages are being updated
                // -> better user experience
                for ($pass = (count($this->updateMap)) ? 0 : 1; $pass < 2; $pass++) {
                    $passLevel = $level;
                    for ($i = $installedPos, $n = 0; $n < count($this->installedPackages); $i++, $n++) {
                        $repeat = false;

                        if ($i == count($this->installedPackages)) {
                            $i = 0;
                        }
                        $literal = new Literal($this->installedPackages[$i], true);

                        if ($this->decisionsContain($literal)) {
                            continue;
                        }

                        // only process updates in first pass
                        /** TODO: && or || ? **/
                        if (0 === $pass && !isset($this->updateMap[$literal->getPackageId()])) {
                            continue;
                        }

                        $rule = null;

                        if (isset($this->packageToUpdateRule[$literal->getPackageId()])) {
                            $rule = $this->packageToUpdateRule[$literal->getPackageId()];
                        }

                        if ((!$rule || $rule->isDisabled()) && isset($this->packageToFeatureRule[$literal->getPackageId()])) {
                            $rule = $this->packageToFeatureRule[$literal->getPackageId()];
                        }

                        if (!$rule || $rule->isDisabled()) {
                            continue;
                        }

                        $updateRuleLiterals = $rule->getLiterals();

                        $decisionQueue = array();
                        if (!isset($this->noUpdate[$literal->getPackageId()]) && (
                            $this->decidedRemove($literal->getPackage()) ||
                            isset($this->updateMap[$literal->getPackageId()]) ||
                            !$literal->equals($updateRuleLiterals[0])
                        )) {
                            foreach ($updateRuleLiterals as $ruleLiteral) {
                                if ($this->decidedInstall($ruleLiteral->getPackage())) {
                                    // already fulfilled
                                    break;
                                }
                                if ($this->undecided($ruleLiteral->getPackage())) {
                                    $decisionQueue[] = $ruleLiteral;
                                }
                            }
                        }

                        if (sizeof($decisionQueue)) {
                            $oLevel = $level;
                            $level = $this->selectAndInstall($level, $decisionQueue, $disableRules, $rule);

                            if (0 === $level) {
                                return;
                            }

                            if ($level <= $oLevel) {
                                $repeat = true;
                            }
                        }

                        // still undecided? keep package.
                        if (!$repeat && $this->undecided($literal->getPackage())) {
                            $oLevel = $level;
                            if (isset($this->cleanDepsMap[$literal->getPackageId()])) {
                                // clean deps removes package
                                $level = $this->setPropagateLearn($level, $literal->invert(), $disableRules, null);
                            } else {
                                // ckeeping package
                                $level = $this->setPropagateLearn($level, $literal, $disableRules, $rule);
                            }


                            if (0 === $level) {
                                return;
                            }

                            if ($level <= $oLevel) {
                                $repeat = true;
                            }
                        }

                        if ($repeat) {
                            if (1 === $level || $level < $passLevel) {
                                // trouble
                                break;
                            }
                            if ($level < $oLevel) {
                                // redo all
                                $n = 0;
                            }

                            // repeat
                            $i--;
                            $n--;
                            continue;
                        }
                    }

                    if ($n < count($this->installedPackages)) {
                        $installedPos = $i; // retry this problem next time
                        break;
                    }

                    $installedPos = 0;
                }

                $systemlevel = $level + 1;

                if ($pass < 2) {
                    // had trouble => retry
                    continue;
                }
            }

            if ($level < $systemLevel) {
                $systemLevel = $level;
            }

            for ($i = 0, $n = 0; $n < count($this->rules); $i++, $n++) {
                if ($i == count($this->rules)) {
                    $i = 0;
                }

                $rule = $this->rules->ruleById($i);
                $literals = $rule->getLiterals();

                if ($rule->isDisabled()) {
                    continue;
                }

                $decisionQueue = array();

                // make sure that
                // * all negative literals are installed
                // * no positive literal is installed
                // i.e. the rule is not fulfilled and we
                // just need to decide on the positive literals
                //
                foreach ($literals as $literal) {
                    if (!$literal->isWanted()) {
                        if (!$this->decidedInstall($literal->getPackage())) {
                            continue 2; // next rule
                        }
                    } else {
                        if ($this->decidedInstall($literal->getPackage())) {
                            continue 2; // next rule
                        }
                        if ($this->undecided($literal->getPackage())) {
                            $decisionQueue[] = $literal;
                        }
                    }
                }

                // need to have at least 2 item to pick from
                if (count($decisionQueue) < 2) {
                    continue;
                }

                $oLevel = $level;
                $level = $this->selectAndInstall($level, $decisionQueue, $disableRules, $rule);

                if (0 === $level) {
                    return;
                }

                if ($level < $systemLevel || $level == 1) {
                    break; // trouble
                }

                // something changed, so look at all rules again
                $n = -1;
            }

//            $this->printDecisionMap();
//            $this->printDecisionQueue();

            if (count($this->branches)) {
                die("minimization unimplemented");
//       /* minimization step */
//      if (solv->branches.count)
//     {
//       int l = 0, lasti = -1, lastl = -1;
//       Id why;
//
//       p = 0;
//       for (i = solv->branches.count - 1; i >= 0; i--)
//         {
//           p = solv->branches.elements[i];
//           if (p < 0)
//         l = -p;
//           else if (p > 0 && solv->decisionmap[p] > l + 1)
//         {
//           lasti = i;
//           lastl = l;
//         }
//         }
//       if (lasti >= 0)
//         {
//           /* kill old solvable so that we do not loop */
//           p = solv->branches.elements[lasti];
//           solv->branches.elements[lasti] = 0;
//           POOL_DEBUG(SAT_DEBUG_SOLVER, "minimizing %d -> %d with %s\n", solv->decisionmap[p], lastl, solvid2str(pool, p));
//           minimizationsteps++;
//
//           level = lastl;
//           revert(solv, level);
//           why = -solv->decisionq_why.elements[solv->decisionq_why.count];
//           assert(why >= 0);
//           olevel = level;
//           level = setpropagatelearn(solv, level, p, disablerules, why);
//           if (level == 0)
//         {
//           queue_free(&dq);
//           queue_free(&dqs);
//           return;
//         }
//           continue;     /* back to main loop */
//         }
//     }
            }

            break;
        }
    }

    public function printDecisionMap()
    {
        echo "DecisionMap: \n";
        foreach ($this->decisionMap as $packageId => $level) {
            if ($level > 0) {
                echo '    +' . $this->pool->packageById($packageId) . "\n";
            } else {
                echo '    -' . $this->pool->packageById($packageId) . "\n";
            }
        }
        echo "\n";
    }

    public function printDecisionQueue()
    {
        echo "DecisionQueue: \n";
        foreach ($this->decisionQueue as $i => $literal) {
            echo '    ' . $literal . ' ' . $this->decisionQueueWhy[$i] . "\n";
        }
        echo "\n";
    }
}