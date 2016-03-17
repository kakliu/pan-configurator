<?php

/*
 * Copyright (c) 2014-2015 Palo Alto Networks, Inc. <info@paloaltonetworks.com>
 * Author: Christophe Painchaud <cpainchaud _AT_ paloaltonetworks.com>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.

 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
*/

class RQuery
{
    /**
     * @var null|string
     */
    public $expression = null;

    /**
     * @var RQuery[]
     */
    public $subQueries = Array();

    /**
     * @var string[]
     */
    public $subQueriesOperators = Array();

    static public $defaultFilters = Array();

    static public $mathOps = Array( '>' => '>', '<' => '<', '=' => '==', '==' => '==', '!=' => '!=', '<=' => '<=', '>=' => '>=' );

    public $objectType = null;

    public $argument = null;


    public $inverted = false;

    public $level = 0;


    public function __construct($objectType, $level = 0)
    {
        $this->level = $level;
        $this->padded = str_pad('', ($this->level+1)*2, ' ');

        $objectType = strtolower($objectType);

        $this->objectType = $objectType;

        if( $this->objectType != "rule" && $this->objectType != "address" && $this->objectType != "service"  )
        {
            derr("unsupported object type '$objectType'");
        }

        if($this->objectType == 'service' )
            $this->contextObject = new ServiceRQueryContext($this);
        elseif($this->objectType == 'address' )
            $this->contextObject = new AddressRQueryContext($this);
        elseif($this->objectType == 'rule' )
            $this->contextObject = new RuleRQueryContext($this);
    }

    /**
     * @param $queryContext Object|string[]
     * @return bool
     */
    public function matchSingleObject($queryContext)
    {
        if( is_array($queryContext) )
        {
            if( !isset($queryContext['object']) )
                derr('no object provided');

            $object = $queryContext['object'];
            $nestedQueries = &$queryContext['nestedQueries'];
        }
        else
        {
            /** @var string[] $nestedQueries */
            $nestedQueries = Array();
            /** @var SecurityRule|Address|AddressGroup|service|ServiceGroup $object */
            $object = $queryContext;
            $queryContext = Array('object' => $object, 'nestedQueries' => $nestedQueries);
        }

        if( count($this->subQueries) == 0 )
        {
            // print $this->padded."about to eval\n";
            if( isset($this->refOperator['Function'] ) )
            {
                $boolReturn =  $this->contextObject->execute($object);
                if( $this->inverted )
                    return !$boolReturn;
                return $boolReturn;
            }
            else
            {
                if( $this->refOperator['arg'] == true )
                {
                    if( isset($this->refOperator['argObjectFinder']) )
                    {
                        $eval = str_replace('!value!', $this->argument, $this->refOperator['argObjectFinder']);
                        if( eval($eval) === FALSE )
                        {
                            derr("\neval code was : $eval\n");
                        }
                        if( $objectFind === null )
                        {
                            fwrite(STDERR, "\n\n**ERROR** cannot find object with name '".$this->argument."'\n\n");
                            exit(1);
                        }
                        if( !is_string($this->refOperator['eval']) )
                        {
                            $boolReturn = $this->refOperator['eval']($object, $nestedQueries, $objectFind);
                        }
                        else
                        {
                            $eval = '$boolReturn = (' . str_replace('!value!', '$objectFind', $this->refOperator['eval']) . ');';

                            if( eval($eval) === FALSE )
                            {
                                derr("\neval code was : $eval\n");
                            }
                        }

                        if( $this->inverted )
                            return !$boolReturn;
                        return $boolReturn;
                    }
                    else
                    {
                        if( !is_string($this->refOperator['eval']) )
                        {
                            $boolReturn = $this->refOperator['eval']($object, $nestedQueries, $this->argument);
                        }
                        else
                        {
                            $eval = '$boolReturn = (' . str_replace('!value!', $this->argument, $this->refOperator['eval']) . ');';

                            if (isset(self::$mathOps[$this->operator]))
                            {
                                $eval = str_replace('!operator!', self::$mathOps[$this->operator], $eval);
                            }

                            if (eval($eval) === FALSE)
                            {
                                derr("\neval code was : $eval\n");
                            }
                        }
                        if ($this->inverted)
                            return !$boolReturn;

                        return $boolReturn;

                    }
                }
                else
                {
                    if( !is_string($this->refOperator['eval']) )
                    {
                        $boolReturn = $this->refOperator['eval']($object, $nestedQueries, null);
                    }
                    else
                    {
                        $eval = '$boolReturn = (' . $this->refOperator['eval'] . ');';

                        if (eval($eval) === FALSE)
                        {
                            derr("\neval code was : $eval\n");
                        }

                    }
                    if( $this->inverted )
                        return !$boolReturn;
                    return $boolReturn;
                }
            }
        }


        $queries = $this->subQueries;
        $operators = $this->subQueriesOperators;

        if( count($queries) == 1 )
        {
            if( $this->inverted )
                return !$queries[0]->matchSingleObject($queryContext);
            return $queries[0]->matchSingleObject($queryContext);
        }

        $results = Array();

        foreach( $queries as $query )
        {
            $results[] = $query->matchSingleObject($queryContext);
        }
        //print_r($results);


        $hasAnd = true;

        // processing 'and' operators
        while( $hasAnd )
        {
            $hasAnd = false;
            $Rkeys = array_keys($results);
            $Rcount = count($results);
            $Okeys = array_keys($operators);
            $Ocount = count($operators);

            for($i=0; $i<$Ocount; $i++)
            {
                if( $operators[$Okeys[$i]] == 'and' )
                {
                    $hasAnd = true;
                    $results[$Rkeys[$i]] = $results[$Rkeys[$i]] && $results[$Rkeys[$i+1]];

                    unset($operators[$Okeys[$i]]);
                    unset($results[$Rkeys[$i+1]]);

                    break;
                }
            }
        }

        foreach( $results as $res )
        {
            if( $res == true )
            {
                if( $this->inverted )
                    return false;
                return true;
            }
        }

        if( $this->inverted )
            return true;
        return false;

    }


    /**
     * @param string $text
     * @param string $errorMessage
     * @return bool|int FALSE if an error occured (see $errorMessage content)
     */
    public function parseFromString($text, &$errorMessage)
    {
        $supportedFilters = &self::$defaultFilters[$this->objectType];

        $len = strlen($text);

        $start = 0;
        $previousClose = 0;
        $end = $len -1;

        $findOpen = strpos( $text, '(', $start);
        $findClose = strpos( $text, ')', $start);

        //print $this->padded."Parsing \"$text\"\n";

        while( $findOpen !== FALSE && ($findClose > $findOpen))
        {

            $newQuery = new RQuery($this->objectType, $this->level + 1);
            $this->subQueries[] = $newQuery;

            $res = $newQuery->parseFromString(substr($text, $findOpen+1), $errorMessage );

            if( $res === false )
                return false;

            if( $findOpen != 0 && $text[$findOpen-1] == '!' )
                $newQuery->inverted = true;

            if( count($this->subQueries) > 1)
            {
                if ($newQuery->inverted)
                    $operator = substr($text, $previousClose + 1, $findOpen - $previousClose - 2);
                else
                    $operator = substr($text, $previousClose + 1, $findOpen - $previousClose - 1);

                $operator = self::extractOperatorFromString($operator, $errorMessage);
                if( $operator === false )
                    return false;

                $this->subQueriesOperators[] = $operator;

                ////print $this->padded."raw operator found: '$operator'\n";
            }


            $previousClose = $findOpen + $res;
            //print $this->padded.'remains to be parsed after subQ extracted: '.substr($text,$previousClose+1)."\n";

            $start = $findOpen + $res +1;
            $findOpen = strpos($text, '(', $start);
            $findClose = strpos($text, ')', $start);
        }

        if( $this->level != 0 )
        {
            $findClose = strpos($text, ')', $previousClose+1 );
            if( $findClose === false )
            {
                $errorMessage = 'cannot find closing )';
                //print $this->padded."test\n";
                return false;
            }
            elseif( count($this->subQueries) == 0  )
            {
                $this->text = substr($text, 0,$findClose);

                if( !$this->extractWordsFromText($this->text, $supportedFilters, $errorMessage) )
                    return false;

                return $findClose+1;
            }
            return $findClose+1;
        }

        // here we are at top level
        if( count($this->subQueries) == 0 )
        {
            //print $this->padded."No subquery found, this is an expression: $text\n";
            $this->text = $text;
            if( !$this->extractWordsFromText($this->text, $supportedFilters, $errorMessage) )
            {
                return false;
            }
        }
        else
        {
            //print $this->padded . "Sub-queries found\n";
            $this->text = $text;
        }

        return 1;
    }

    private function extractWordsFromText($text,&$supportedOperations, &$errorMessage)
    {
        $text = trim($text);

        $pos = strpos($text, ' ');

        if( $pos === false )
            $pos = strlen($text);

        $this->field = strtolower(substr($text, 0, $pos));

        if( strlen($this->field) < 1 || !isset($supportedOperations[$this->field]) )
        {
            $errorMessage = "unsupported field name '".$this->field."' in expression '$text'";
            //derr();
            return false;
        }

        $subtext = substr($text, $pos+1);
        $pos = strpos($subtext, ' ');

        if( $pos === false )
            $pos = strlen($subtext);


        $this->operator = strtolower(substr($subtext, 0, $pos));


        $isMathOp = false;

        if( isset(self::$mathOps[$this->operator]) )
        {
            $isMathOp = true;
        }

        if( strlen($this->field) < 1 ||
              !( isset($supportedOperations[$this->field]['operators'][$this->operator]) ||
                  ($isMathOp && isset($supportedOperations[$this->field]['operators']['>,<,=,!'])) ) )
        {
            $errorMessage = "unsupported operator name '".$this->operator."' in expression '$text'";
            return false;
        }

        if( $isMathOp )
            $this->refOperator = &$supportedOperations[$this->field]['operators']['>,<,=,!'];
        else
            $this->refOperator = &$supportedOperations[$this->field]['operators'][$this->operator];

        $subtext = substr($subtext, $pos+1);

        if( $this->refOperator['arg'] === false && strlen(trim($subtext)) != 0 )
        {
            $errorMessage = "this field/operator does not support argument in expression '$text'";
            return false;
        }


        if( $this->refOperator['arg'] === false )
            return true;


        $subtext = trim($subtext);

        if( strlen($subtext) < 1)
        {
            $errorMessage = "missing arguments in expression '$text'";
            return false;
        }

        $this->argument = $subtext;


        return true;

    }

    static private function extractOperatorFromString($text, &$errorMessage)
    {
        $text = trim($text);

        if( count(explode(' ', $text)) != 1 )
        {
            $errorMessage = "unsupported operator: '$text'. Supported is: or,and,&&,||";
            return false;
        }

        $text = strtolower($text);

        if( $text == 'or' || $text == '||' )
            return 'or';

        if( $text == 'and' || $text == '&&' )
            return 'and';

        $errorMessage = "unsupported operator: '$text'. Supported is: or,and,&&,||";
        return false;

    }


    public function display( $indentLevel = 0)
    {
        if( $indentLevel == 0 )
            print $this->sanitizedString();
        else
            print str_pad($this->sanitizedString(), $indentLevel);
    }

    public function sanitizedString()
    {
        $retString = '';

        if( $this->inverted )
            $retString .= '!';

        if( $this->level != 0 )
            $retString .= '(';

        $loop = 0;

        if( count($this->subQueries) > 0 )
        {
            $first = true;
            foreach ($this->subQueries as $query)
            {
                if( $loop > 0 )
                    $retString .= ' '.$this->subQueriesOperators[$loop-1].' ';

                $retString .= $query->sanitizedString();
                $loop++;
            }
        }
        else
        {
            if( isset($this->argument) )
                $retString .= $this->field.' '.$this->operator.' '.$this->argument;
            else
                $retString .= $this->field.' '.$this->operator;
        }

        if( $this->level != 0 )
            $retString .= ")";

        return $retString;
    }

    public function toString()
    {
        return 'RQuery::'.$this->text;
    }
}

// <editor-fold desc=" ***** Rule filters *****" defaultstate="collapsed" >

//                                              //
//                Zone Based Actions            //
//                                              //
RQuery::$defaultFilters['rule']['from']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->from->hasZone($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->from->parentCentralStore->find('!value!');"

);
RQuery::$defaultFilters['rule']['from']['operators']['has.only'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->from->count() == 1 && $object->from->hasZone($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->from->parentCentralStore->find('!value!');"
);

RQuery::$defaultFilters['rule']['to']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->to->hasZone($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->to->parentCentralStore->find('!value!');"

);
RQuery::$defaultFilters['rule']['to']['operators']['has.only'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->to->count() == 1 && $object->to->hasZone($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->to->parentCentralStore->find('!value!');"
);


RQuery::$defaultFilters['rule']['from']['operators']['has.regex'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */

        foreach($object->from->zones() as $zone )
        {
            $matching = preg_match($value, $zone->name());
            if( $matching === FALSE )
                derr("regular expression error on '$value'");
            if( $matching === 1 )
                return true;
        }

        return false;
    },
    'arg' => true,
);
RQuery::$defaultFilters['rule']['to']['operators']['has.regex'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        foreach($object->to->zones() as $zone )
        {
            $matching = preg_match( $value, $zone->name() );
            if( $matching === FALSE )
                derr("regular expression error on '$value'");
            if( $matching === 1 )
                return true;
        }

        return false;
    },
    'arg' => true,
);

RQuery::$defaultFilters['rule']['from.count']['operators']['>,<,=,!'] = Array(
    'eval' => "\$object->from->count() !operator! !value!",
    'arg' => true
);
RQuery::$defaultFilters['rule']['to.count']['operators']['>,<,=,!'] = Array(
    'eval' => "\$object->to->count() !operator! !value!",
    'arg' => true
);

RQuery::$defaultFilters['rule']['from']['operators']['is.any'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->from->isAny();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['to']['operators']['is.any'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->to->isAny();
    },
    'arg' => false
);

//                                              //
//                NAT Dst/Src Based Actions            //
//                                              //
RQuery::$defaultFilters['rule']['snathost']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        if (!$object->isNatRule()) return false;

        return $object->snathosts->has($value) === true;

    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->owner->owner->addressStore->find('!value!');"

);
RQuery::$defaultFilters['rule']['dnathost']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value) {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        if (!$object->isNatRule()) return false;
        if ($object->dnathost === null) return false;

        return $object->dnathost === $value;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->owner->owner->addressStore->find('!value!');"
);
//                                              //
//                Dst/Src Based Actions            //
//                                              //
RQuery::$defaultFilters['rule']['src']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->has($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->source->parentCentralStore->find('!value!');"

);
RQuery::$defaultFilters['rule']['src']['operators']['has.only'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->count() == 1 && $object->source->has($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->source->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['rule']['src']['operators']['has.recursive'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->hasObjectRecursive($value, false) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->source->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['rule']['src']['operators']['has.recursive.regex'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */

        $members = $object->source->membersExpanded(true);

        foreach( $members as $member)
        {
            $matching = preg_match($value, $member->name());
            if( $matching === FALSE )
                derr("regular expression error on '$value'");
            if( $matching === 1 )
                return true;
        }
        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['dst']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule  $object */
        return $object->destination->has($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->destination->parentCentralStore->find('!value!');"

);
RQuery::$defaultFilters['rule']['dst']['operators']['has.only'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->destination->count() == 1 && $object->destination->has($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->destination->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['rule']['dst']['operators']['has.recursive'] = Array(
    'eval' => '$object->destination->hasObjectRecursive(!value!, false) === true',
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->destination->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['rule']['dst']['operators']['has.recursive.regex'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */

        $members = $object->destination->membersExpanded(true);

        foreach( $members as $member)
        {
            $matching = preg_match($value, $member->name());
            if( $matching === FALSE )
                derr("regular expression error on '$value'");
            if( $matching === 1 )
                return true;
        }
        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['src']['operators']['is.any'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->count() == 0;
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['dst']['operators']['is.any'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->destination->count() == 0;
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['src']['operators']['is.negated'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        if( $object->isNatRule() )
            return false;

        return $object->sourceIsNegated();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['dst']['operators']['is.negated'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        if( $object->isNatRule() )
            return false;
        
        return $object->destinationIsNegated();
    },
    'arg' => false
);

RQuery::$defaultFilters['rule']['src']['operators']['included-in.full'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->includedInIP4Network($value) == 1;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['src']['operators']['included-in.partial'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->includedInIP4Network($value) == 2;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['src']['operators']['included-in.full.or.partial'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->includedInIP4Network($value) > 0;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['src']['operators']['includes.full'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->includesIP4Network($value) == 1;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['src']['operators']['includes.partial'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->includesIP4Network($value) == 2;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['src']['operators']['includes.full.or.partial'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->source->includesIP4Network($value) > 0;
    },
    'arg' => true
);

RQuery::$defaultFilters['rule']['dst']['operators']['included-in.full'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->destination->includedInIP4Network($value) == 1;
    },
    'arg' => true,
    'argDesc' => 'ie: 192.168.0.0/24 | 192.168.50.10/32 | 192.168.50.10 | 10.0.0.0-10.33.0.0'
);
RQuery::$defaultFilters['rule']['dst']['operators']['included-in.partial'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->destination->includedInIP4Network($value) == 2;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['dst']['operators']['included-in.full.or.partial'] = Array(
    'eval' => "\$object->destination->includedInIP4Network('!value!') > 0",
    'arg' => true
);
RQuery::$defaultFilters['rule']['dst']['operators']['includes.full'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->destination->includesIP4Network($value) == 1;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['dst']['operators']['includes.partial'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->destination->includesIP4Network($value) == 2;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['dst']['operators']['includes.full.or.partial'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->destination->includesIP4Network($value) > 0;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['src']['operators']['has.from.query'] = Array(
    'eval' => function( $object, &$nestedQueries, $argument)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        if( $object->source->count() == 0 )
            return false;

        if( $argument === null || !isset($nestedQueries[$argument]) )
            derr("cannot find nested query called '$argument'");

        $errorMessage = '';
        $rQuery = new RQuery('address');
        if( $rQuery->parseFromString($nestedQueries[$argument], $errorMessage) === false )
            derr('nested query execution error : '.$errorMessage);

        foreach( $object->source->all() as $member )
        {
            if( $rQuery->matchSingleObject(Array('object' => $member, 'nestedQueries' => &$nestedQueries)) )
                return true;
        }

        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['dst']['operators']['has.from.query'] = Array(
    'eval' => function( $object, &$nestedQueries, $argument)
                {
                    /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
                    if( $object->destination->count() == 0 )
                        return false;

                    if( $argument === null || !isset($nestedQueries[$argument]) )
                        derr("cannot find nested query called '$argument'");

                    $errorMessage = '';
                    $rQuery = new RQuery('address');
                    if( $rQuery->parseFromString($nestedQueries[$argument], $errorMessage) === false )
                        derr('nested query execution error : '.$errorMessage);

                    foreach( $object->destination->all() as $member )
                    {
                        if( $rQuery->matchSingleObject(Array('object' => $member, 'nestedQueries' => &$nestedQueries)) )
                            return true;
                    }

                    return false;
                },
    'arg' => true
);
RQuery::$defaultFilters['rule']['src']['operators']['has.recursive.from.query'] = Array(
    'eval' => function( $object, &$nestedQueries, $argument)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        if( $object->source->count() == 0 )
            return false;

        if( $argument === null || !isset($nestedQueries[$argument]) )
            derr("cannot find nested query called '$argument'");

        $errorMessage = '';
        $rQuery = new RQuery('address');
        if( $rQuery->parseFromString($nestedQueries[$argument], $errorMessage) === false )
            derr('nested query execution error : '.$errorMessage);

        foreach( $object->source->membersExpanded() as $member )
        {
            if( $rQuery->matchSingleObject(Array('object' => $member, 'nestedQueries' => &$nestedQueries)) )
                return true;
        }

        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['dst']['operators']['has.recursive.from.query'] = Array(
    'eval' => function( $object, &$nestedQueries, $argument)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        if( $object->destination->count() == 0 )
            return false;

        if( $argument === null || !isset($nestedQueries[$argument]) )
            derr("cannot find nested query called '$argument'");

        $errorMessage = '';
        $rQuery = new RQuery('address');
        if( $rQuery->parseFromString($nestedQueries[$argument], $errorMessage) === false )
            derr('nested query execution error : '.$errorMessage);

        foreach( $object->destination->all() as $member )
        {
            if( $rQuery->matchSingleObject(Array('object' => $member, 'nestedQueries' => &$nestedQueries)) )
                return true;
        }

        return false;
    },
    'arg' => true
);


//                                                //
//                Tag Based filters              //
//                                              //
RQuery::$defaultFilters['rule']['tag']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->tags->hasTag($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->tags->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['rule']['tag']['operators']['has.nocase'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->tags->hasTag($value, false) === true;
    },
    'arg' => true
    //'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->tags->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['rule']['tag']['operators']['has.regex'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        foreach($object->tags->tags() as $tag )
        {
            $matching = preg_match( $value, $tag->name() );
            if( $matching === FALSE )
                derr("regular expression error on '$value'");
            if( $matching === 1 )
                return true;
        }

        return false;
    },
    'arg' => true,
);
RQuery::$defaultFilters['rule']['tag.count']['operators']['>,<,=,!'] = Array(
    'eval' => "\$object->tags->count() !operator! !value!",
    'arg' => true
);



//                                              //
//          Application properties              //
//                                              //
RQuery::$defaultFilters['rule']['app']['operators']['is.any'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->apps->isAny();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['app']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */
        return $object->apps->hasApp($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->apps->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['rule']['app']['operators']['has.nocase'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->apps->hasApp($value, false) === true;
    },
    'arg' => true
    //'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->tags->parentCentralStore->find('!value!');"
);


//                                              //
//          Services properties                 //
//                                              //
RQuery::$defaultFilters['rule']['service']['operators']['is.any'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->services->isAny();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['service']['operators']['is.application-default'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->services->isApplicationDefault();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['service']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->services->has($value) === true;
        },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->services->parentCentralStore->find('!value!');"
);


//                                              //
//                SecurityProfile properties    //
//                                              //
RQuery::$defaultFilters['rule']['secprof']['operators']['not.set'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        if( !$object->isSecurityRule() )
            return false;

        return $object->securityProfileIsBlank();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['secprof']['operators']['is.set'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        if( !$object->isSecurityRule() )
            return false;

        return !$object->securityProfileIsBlank();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['secprof']['operators']['is.profile'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->securityProfileType() == "profile";
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['secprof']['operators']['is.group'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->securityProfileType() == "group";
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['secprof']['operators']['group.is'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->securityProfileType() == "group" && $object->securityProfileGroup() == $value;
    },
    'arg' => true
);


//                                              //
//                Other properties              //
//                                              //
RQuery::$defaultFilters['rule']['action']['operators']['is.deny'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->actionIsDeny();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['action']['operators']['is.negative'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        if( !$object->isSecurityRule() )
            return false;
        return $object->actionIsNegative();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['action']['operators']['is.allow'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        if( !$object->isSecurityRule() )
            return false;
        return $object->actionIsAllow();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['log']['operators']['at.start'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        if( !$object->isSecurityRule() )
            return false;
        return $object->logStart();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['log']['operators']['at.end'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        if( !$object->isSecurityRule() )
            return false;
        return $object->logEnd();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['logprof']['operators']['is.set'] = Array(
    'eval' => function($rule, &$nestedQueries)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $rule */
        if( !$rule->isSecurityRule() )
            return false;

        if( $rule->logSetting() === null || $rule->logSetting() == '' )
            return false;

        return true;
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['logprof']['operators']['is'] = Array(
    'eval' => function($rule, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $rule */
        if( !$rule->isSecurityRule() )
            return false;

        if( $rule->logSetting() === null )
            return false;

        if( $rule->logSetting() == $value )
            return true;

        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['rule']['operators']['is.prerule'] = Array(
    'eval' => '$object->isPreRule()',
    'arg' => false
);
RQuery::$defaultFilters['rule']['rule']['operators']['is.postrule'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->isPostRule();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['rule']['operators']['is.disabled'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->isDisabled();
    },
    'arg' => false
);

RQuery::$defaultFilters['rule']['location']['operators']['is'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        /** @var string $value */
        $owner = $object->owner->owner;
        if( strtolower($value) == 'shared' )
        {
            if( $owner->isPanorama() )
                return true;
            if( $owner->isFirewall() )
                return true;
            return false;
        }
        if( strtolower($value) == strtolower($owner->name()) )
            return true;

        return false;
    },
    'arg' => true
);

RQuery::$defaultFilters['rule']['rule']['operators']['is.unused.fast'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        if( !$object->isSecurityRule() )
            derr("unsupported filter : this is not a security rule.".$object->toString());

        $sub = $object->owner->owner;
        if( !$sub->isVirtualSystem() && !$sub->isDeviceGroup() )
            derr("this is filter is only supported on non Shared rules".$object->toString());

        $connector = findConnector($sub);

        if( $connector === null )
            derr("this filter is available only from API enabled PANConf objects");

        if( !isset($sub->apiCache) )
            $sub->apiCache = Array();

        // caching results for speed improvements
        if( !isset($sub->apiCache['unusedSecurity']) )
        {
            $sub->apiCache['unusedSecurity'] = Array();

            $apiCmd = '<show><running><rule-use><rule-base>security</rule-base><type>unused</type><vsys>' . $sub->name() . '</vsys></rule-use></running></show>';

            if( $sub->isVirtualSystem() )
            {
                $apiResult = $connector->sendCmdRequest($apiCmd);

                $rulesXml = DH::findXPath('/result/rules/entry', $apiResult);
                for ($i = 0; $i < $rulesXml->length; $i++)
                {
                    $ruleName = $rulesXml->item($i)->textContent;
                    $sub->apiCache['unusedSecurity'][$ruleName] = $ruleName;
                }
            }
            else
            {
                $devices = $sub->getDevicesInGroup();
                $firstLoop = true;

                foreach($devices as $device)
                {
                    $newConnector = new PanAPIConnector($connector->apihost, $connector->apikey, 'panos-via-panorama', $device['serial']);
                    $newConnector->setShowApiCalls($connector->showApiCalls);
                    $tmpCache = Array();

                    foreach($device['vsyslist'] as $vsys)
                    {
                        $apiCmd = '<show><running><rule-use><rule-base>security</rule-base><type>unused</type><vsys>' . $vsys . '</vsys></rule-use></running></show>';
                        $apiResult = $newConnector->sendCmdRequest($apiCmd);

                        $rulesXml = DH::findXPath('/result/rules/entry', $apiResult);

                        for ($i = 0; $i < $rulesXml->length; $i++)
                        {
                            $ruleName = $rulesXml->item($i)->textContent;
                            if( $firstLoop )
                                $sub->apiCache['unusedSecurity'][$ruleName] = $ruleName;
                            else
                            {
                                $tmpCache[$ruleName] = $ruleName;
                            }
                        }

                        if( !$firstLoop )
                        {
                            foreach( $sub->apiCache['unusedSecurity'] as $unusedEntry )
                            {
                                if( !isset($tmpCache[$unusedEntry]) )
                                    unset($sub->apiCache['unusedSecurity'][$unusedEntry]);
                            }
                        }

                        $firstLoop = false;
                    }
                }
            }
        }

        if( isset($sub->apiCache['unusedSecurity'][$object->name()]) )
            return true;

        return false;
    },
    'arg' => false
);


RQuery::$defaultFilters['rule']['name']['operators']['eq'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        return $object->name() == $value;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['name']['operators']['regex'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        $matching = preg_match($context->value, $context->object->name());
        if( $matching === FALSE )
            derr("regular expression error on '{$context->value}'");
        if( $matching === 1 )
            return true;
        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['name']['operators']['eq.nocase'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        return strtolower($context->object->name()) == strtolower($context->value);
    },
    'arg' => true
);
RQuery::$defaultFilters['rule']['name']['operators']['contains'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        return stripos($context->object->name(), $context->value) !== false;
    },
    'arg' => true
);

RQuery::$defaultFilters['rule']['name']['operators']['is.in.file'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        $object = $context->object;

        if( !isset($context->cachedList) )
        {
            $text = file_get_contents($context->value);

            if( $text === false )
                derr("cannot open file '{$context->value}");

            $lines = explode("\n", $text);
            foreach( $lines as  $line)
            {
                $line = trim($line);
                if(strlen($line) == 0)
                    continue;
                $list[$line] = true;
            }

            $context->cachedList = &$list;
        }
        else
            $list = &$context->cachedList;

        return isset($list[$object->name()]);
    },
    'arg' => true
);

//                                              //
//                UserID properties             //
//                                              //
RQuery::$defaultFilters['rule']['user']['operators']['is.any'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        $rule = $context->object;
        if( $rule->isDecryptionRule() )
            return false;
        if( $rule->isNatRule() )
            return false;

        return $rule->userID_IsAny();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['user']['operators']['is.known'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        $rule = $context->object;
        if( $rule->isDecryptionRule() )
            return false;
        if( $rule->isNatRule() )
            return false;

        return $rule->userID_IsKnown();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['user']['operators']['is.unknown'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        $rule = $context->object;
        if( $rule->isDecryptionRule() )
            return false;
        if( $rule->isNatRule() )
            return false;

        return $rule->userID_IsUnknown();
    },
    'arg' => false
);
RQuery::$defaultFilters['rule']['user']['operators']['is.prelogon'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        $rule = $context->object;
        if( $rule->isDecryptionRule() )
            return false;
        if( $rule->isNatRule() )
            return false;

        return $rule->userID_IsPreLogon();
    },
    'arg' => false
);


RQuery::$defaultFilters['rule']['target']['operators']['is.any'] = Array(
    'Function' => function(RuleRQueryContext $context )
    {
        return $context->object->target_isAny();
    },
    'arg' => false
);

RQuery::$defaultFilters['rule']['target']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule $object */

        $vsys = 'vsys1';
        $serial = '';
        $ex = explode('/', $value);

        if( count($ex) > 2 )
            derr("unsupported syntax for target: '{$value}'. Expected something like : 00F120CCC/vsysX");

        if( count($ex) == 1 )
            $serial = $value;
        else
        {
            $serial = $ex[0];
            $vsys = $ex[1];
        }

        return $object->target_hasDeviceAndVsys($serial, $vsys);
    },
    'arg' => true
);


RQuery::$defaultFilters['rule']['description']['operators']['is.empty'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */

        $desc = $object->description();

        if( $desc === null || strlen($desc) == 0 )
            return true;

        return false;
    },
    'arg' => false,
);


RQuery::$defaultFilters['rule']['description']['operators']['regex'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Rule|SecurityRule|NatRule|DecryptionRule|AppOverrideRule|CaptivePortalRule|AppOverrideRule $object */
        $matching = preg_match($value, $object->description());
        if( $matching === FALSE )
            derr("regular expression error on '$value'");
        if( $matching === 1 )
            return true;
        return false;
    },
    'arg' => true,
);

// </editor-fold>


//
//          Address Filters
//

// <editor-fold desc=" ***** Address filters *****" defaultstate="collapsed" >

RQuery::$defaultFilters['address']['refcount']['operators']['>,<,=,!'] = Array(
    'eval' => '$object->countReferences() !operator! !value!',
    'arg' => true
);
RQuery::$defaultFilters['address']['object']['operators']['is.unused'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        return $object->countReferences() == 0;
    },
    'arg' => false
);
RQuery::$defaultFilters['address']['object']['operators']['is.group'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        return $object->isGroup() == true;
    },
    'arg' => false
);
RQuery::$defaultFilters['address']['object']['operators']['is.tmp'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        return $object->isTmpAddr() == true;
    },
    'arg' => false
);
RQuery::$defaultFilters['address']['name']['operators']['eq'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        return $object->name() == $value;
    },
    'arg' => true
);
RQuery::$defaultFilters['address']['name']['operators']['eq.nocase'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        return strtolower($object->name()) == strtolower($value);
    },
    'arg' => true
);
RQuery::$defaultFilters['address']['name']['operators']['contains'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup $object */
        return strpos($object->name(), $value) !== false;
    },
    'arg' => true
);
RQuery::$defaultFilters['address']['name']['operators']['regex'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Address|AddressGroup  $object */
        $matching = preg_match($value, $object->name());
        if( $matching === FALSE )
            derr("regular expression error on '$value'");
        if( $matching === 1 )
            return true;
        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['address']['name']['operators']['is.in.file'] = Array(
    'Function' => function(AddressRQueryContext $context )
    {
        $object = $context->object;

        if( !isset($context->cachedList) )
        {
            $text = file_get_contents($context->value);

            if( $text === false )
                derr("cannot open file '{$context->value}");

            $lines = explode("\n", $text);
            foreach( $lines as  $line)
            {
                $line = trim($line);
                if(strlen($line) == 0)
                    continue;
                $list[$line] = true;
            }

            $context->cachedList = &$list;
        }
        else
            $list = &$context->cachedList;

        return isset($list[$object->name()]);
    },
    'arg' => true
);

RQuery::$defaultFilters['address']['members.count']['operators']['>,<,=,!'] = Array(
    'eval' => "\$object->isGroup() && \$object->count() !operator! !value!",
    'arg' => true
);
RQuery::$defaultFilters['address']['tag.count']['operators']['>,<,=,!'] = Array(
    'eval' => "\$object->tags->count() !operator! !value!",
    'arg' => true
);
RQuery::$defaultFilters['address']['tag']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        return $object->tags->hasTag($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->tags->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['address']['tag']['operators']['has.nocase'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        return $object->tags->hasTag($value, false) === true;
    },
    'arg' => true
    //'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->tags->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['address']['location']['operators']['is'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
                {
                    /** @var Address|AddressGroup $object */
                    /** @var string $value */
                    $owner = $object->owner->owner;
                    if( strtolower($value) == 'shared' )
                    {
                        if( $owner->isPanorama() )
                            return true;
                        if( $owner->isFirewall() )
                            return true;
                        return false;
                    }
                    if( strtolower($value) == strtolower($owner->name()) )
                        return true;

                    return false;
                },
    'arg' => true
);
RQuery::$defaultFilters['address']['value']['operators']['string.eq'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup $object */
        /** @var string $value */

        if( $object->isGroup() )
            return false;

        if( $object->isAddress() )
        {
            if( $object->type() == 'ip-range' || $object->type() == 'ip-netmask' )
            {
                if( $object->value() == $value )
                    return true;
            }
        }

        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['address']['value']['operators']['ip4.match.exact'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        /** @var string $value */

        $values = explode(',', $value);
        $mapping = new IP4Map();

        $count = 0;
        foreach( $values as $net )
        {
            $net = trim($net);
            if( strlen($net) < 1 )
                derr("empty network/IP name provided for argument #$count");
            $mapping->addMap(IP4Map::mapFromText($net));
            $count++;
        }

        return $object->getIP4Mapping()->equals($mapping);
    },
    'arg' => true
);
RQuery::$defaultFilters['address']['value']['operators']['ip4.included-in'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Address|AddressGroup  $object */
        /** @var string $value */

        if( $object->isAddress() && $object->type() == 'fqdn' )
            return false;

        $values = explode(',', $value);
        $mapping = new IP4Map();

        $count = 0;
        foreach( $values as $net )
        {
            $net = trim($net);
            if( strlen($net) < 1 )
                derr("empty network/IP name provided for argument #$count");
            $mapping->addMap(IP4Map::mapFromText($net));
            $count++;
        }

        return $object->getIP4Mapping()->includedInOtherMap($mapping) == 1;
    },
    'arg' => true
);
// </editor-fold>


//
//          Service Filters
//

// <editor-fold desc=" ***** Service filters *****" defaultstate="collapsed" >
RQuery::$defaultFilters['service']['refcount']['operators']['>,<,=,!'] = Array(
    'eval' => '$object->countReferences() !operator! !value!',
    'arg' => true
);
RQuery::$defaultFilters['service']['object']['operators']['is.unused'] = Array(
    'Function' => function(ServiceRQueryContext $context )
    {
        return $context->object->countReferences() == 0;
    },
    'arg' => false
);
RQuery::$defaultFilters['service']['name']['operators']['is.in.file'] = Array(
    'Function' => function(ServiceRQueryContext $context )
    {
        $object = $context->object;

        if( !isset($context->cachedList) )
        {
            $text = file_get_contents($context->value);

            if( $text === false )
                derr("cannot open file '{$context->value}");

            $lines = explode("\n", $text);
            foreach( $lines as  $line)
            {
                $line = trim($line);
                if(strlen($line) == 0)
                    continue;
                $list[$line] = true;
            }

            $context->cachedList = &$list;
        }
        else
            $list = &$context->cachedList;

        return isset($list[$object->name()]);
    },
    'arg' => true
);
RQuery::$defaultFilters['service']['object']['operators']['is.group'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Service|ServiceGroup $object */
        return $object->isGroup();
    },
    'arg' => false
);
RQuery::$defaultFilters['service']['object']['operators']['is.tmp'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Service|ServiceGroup $object */
        return $object->isTmpSrv();
    },
    'arg' => false
);
RQuery::$defaultFilters['service']['name']['operators']['eq'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Service|ServiceGroup $object */
        return $object->name() == $value;
    },
    'arg' => true
);
RQuery::$defaultFilters['service']['name']['operators']['eq.nocase'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Service|ServiceGroup $object */
        return strtolower($object->name()) == strtolower($value);
    },
    'arg' => true
);
RQuery::$defaultFilters['service']['name']['operators']['contains'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Service|ServiceGroup $object */
        return strpos($object->name(), $value) !== false;
    },
    'arg' => true
);
RQuery::$defaultFilters['service']['name']['operators']['regex'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Service|ServiceGroup $object */
        $matching = preg_match($value, $object->name());
        if( $matching === FALSE )
            derr("regular expression error on '$value'");
        if( $matching === 1 )
            return true;
        return false;
    },
    'arg' => true
);
RQuery::$defaultFilters['service']['members.count']['operators']['>,<,=,!'] = Array(
    'eval' => "\$object->isGroup() && \$object->count() !operator! !value!",
    'arg' => true
);
RQuery::$defaultFilters['service']['tag']['operators']['has'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Service|ServiceGroup $object */
        return $object->tags->hasTag($value) === true;
    },
    'arg' => true,
    'argObjectFinder' => "\$objectFind=null;\n\$objectFind=\$object->tags->parentCentralStore->find('!value!');"
);
RQuery::$defaultFilters['service']['tag']['operators']['has.nocase'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {   /** @var Service|ServiceGroup $object */
        return $object->tags->hasTag($value, false) === true;
    },
    'arg' => true
);
RQuery::$defaultFilters['service']['location']['operators']['is'] = Array(
    'eval' => function($object, &$nestedQueries, $value)
    {
        /** @var Service|ServiceGroup $object */
        /** @var string $value */
        $owner = $object->owner->owner;
        if( strtolower($value) == 'shared' )
        {
            if( $owner->isPanorama() )
                return true;
            if( $owner->isFirewall() )
                return true;
            return false;
        }
        if( strtolower($value) == strtolower($owner->name()) )
            return true;

        return false;
    },
    'arg' => true
);
// </editor-fold>

/**
 * Class RQueryContext
 * @ignore
 */
class RQueryContext
{


}

/**
 * Class RuleRQueryContext
 * @ignore
 */
class RuleRQueryContext extends RQueryContext
{
    /** @var  SecurityRule|NatRule|DecryptionRule|AppOverrideRule */
    public $object;
    public $value;

    public $rQueryObject;

    public $nestedQueries;

    function __construct(RQuery $r, $value = null, $nestedQueries = null)
    {
        $this->rQueryObject = $r;
        $this->value = $value;

        if( $nestedQueries === null )
            $this->nestedQueries = Array();
        else
            $this->nestedQueries = &$nestedQueries;
    }

    /**
     * @param $object SecurityRule|NatRule|DecryptionRule|AppOverrideRule
     * @return bool
     */
    function execute($object, $nestedQueries = null)
    {
        if( $nestedQueries !== null )
            $this->nestedQueries = &$nestedQueries;

        $this->object = $object;
        $this->value = &$this->rQueryObject->argument;

        return $this->rQueryObject->refOperator['Function']($this);
    }

}

/**
 * Class AddressRQueryContext
 * @ignore
 */
class AddressRQueryContext extends RQueryContext
{
    /** @var  Address|AddressGroup */
    public $object;
    public $value;

    public $rQueryObject;

    public $nestedQueries;

    function __construct(RQuery $r, $value = null, $nestedQueries = null)
    {
        $this->rQueryObject = $r;
        $this->value = $value;

        if( $nestedQueries === null )
            $this->nestedQueries = Array();
        else
            $this->nestedQueries = &$nestedQueries;
    }

    /**
     * @param $object Address|AddressGroup
     * @return bool
     */
    function execute($object, $nestedQueries = null)
    {
        if( $nestedQueries !== null )
            $this->nestedQueries = &$nestedQueries;

        $this->object = $object;
        $this->value = &$this->rQueryObject->argument;

        return $this->rQueryObject->refOperator['Function']($this);
    }

}

/**
 * Class ServiceRQueryContext
 * @ignore
 */
class ServiceRQueryContext extends RQueryContext
{
    /** @var  Service|ServiceGroup */
    public $object;
    public $value;

    public $rQueryObject;

    public $nestedQueries;

    function __construct(RQuery $r, $value = null, $nestedQueries = null)
    {
        $this->rQueryObject = $r;
        $this->value = $value;

        if( $nestedQueries === null )
            $this->nestedQueries = Array();
        else
            $this->nestedQueries = &$nestedQueries;
    }

    /**
     * @param $object Service|ServiceGroup
     * @return bool
     */
    function execute($object, $nestedQueries = null)
    {
        if( $nestedQueries !== null )
            $this->nestedQueries = &$nestedQueries;

        $this->object = $object;
        $this->value = &$this->rQueryObject->argument;

        return $this->rQueryObject->refOperator['Function']($this);
    }

}



