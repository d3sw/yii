<?php
/**
 * CHtmlPurifier class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link https://www.yiiframework.com/
 * @copyright 2008-2013 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

if(!class_exists('HTMLPurifier_Bootstrap',false))
{
	require_once(Yii::getPathOfAlias('system.vendors.htmlpurifier').DIRECTORY_SEPARATOR.'HTMLPurifier.standalone.php');
	HTMLPurifier_Bootstrap::registerAutoload();
}

/**
     * CHtmlPurifier is wrapper of {@link http://htmlpurifier.org HTML Purifier}.
 *
 * CHtmlPurifier removes all malicious code (better known as XSS) with a thoroughly audited,
 * secure yet permissive whitelist. It will also make sure the resulting code
 * is standard-compliant.
 *
 * CHtmlPurifier can be used as either a widget or a controller filter.
 *
 * Note: since HTML Purifier is a big package, its performance is not very good.
 * You should consider either caching the purification result or purifying the user input
 * before saving to database.
 *
 * Usage as a class:
 * <pre>
 * $p = new CHtmlPurifier();
 * $p->options = array('URI.AllowedSchemes'=>array(
 *   'http' => true,
 *   'https' => true,
 * ));
 * $text = $p->purify($text);
 * </pre>
 *
 * Usage as validation rule:
 * <pre>
 * array('text','filter','filter'=>array($obj=new CHtmlPurifier(),'purify')),
 * </pre>
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @package system.web.widgets
 * @since 1.0
 */
class CHtmlPurifier extends COutputProcessor
{
	/**
	 * @var object the HTML Purifier instance.
	 */
	private $_purifier;
	/**
	 * @var mixed the options to be passed to HTML Purifier instance.
	 * This can be a HTMLPurifier_Config object,  an array of directives (Namespace.Directive => Value)
	 * or the filename of an ini file.
	 * @see http://htmlpurifier.org/live/configdoc/plain.html
	 */
	private $_options=null;
	
	/**
	 * Processes the captured output.
	 * This method purifies the output using {@link http://htmlpurifier.org HTML Purifier}.
	 * @param string $output the captured output to be processed
	 */
	public function processOutput($output)
	{
		$output=$this->purify($output);
		parent::processOutput($output);
	}
	
	/**
	 * Purifies the HTML content by removing malicious code.
	 * @param mixed $content the content to be purified.
	 * @return mixed the purified content 
	 */
	public function purify($content)
	{
		if(is_array($content))
			$content=array_map(array($this,'purify'),$content);
		else
			$content=$this->getPurifier()->purify($content);
		return $content;
	}
	
	/**
	 * Set the options for HTML Purifier and create a new HTML Purifier instance based on these options.
	 * @param mixed $options the options for HTML Purifier
	 * @return static the object instance itself
	 */
	public function setOptions($options)
	{
		$this->_options=$options;
		$this->createNewHtmlPurifierInstance();
		return $this;
	}
	
	/**
	 * Get the options for the HTML Purifier instance.
	 * @return mixed the HTML Purifier instance options
	 */
	public function getOptions()
	{
		return $this->_options;
	}
	
	/**
	 * Get the HTML Purifier instance or create a new one if it doesn't exist.
	 * @return HTMLPurifier
	 */
	protected function getPurifier()
	{
		if($this->_purifier!==null)
			return $this->_purifier;
		return $this->createNewHtmlPurifierInstance();
	}
	
	/**
	 * Create a new HTML Purifier instance.
	 * @return HTMLPurifier
	 */
	protected function createNewHtmlPurifierInstance()
	{
		$options=$this->getOptions();
		if($options===null)
			$options=array();
		if(is_array($options))
		{
			// HTMLPurifier 4.19 dropped the image-length defaults of 4.15 (HTML.MaxImgLength 1200,
			// CSS.MaxImgLength '1200px'); keep them so purified output does not change for callers
			// that do not set these options themselves (d3sw 1.1.32.3).
			foreach(array('HTML.MaxImgLength'=>1200,'CSS.MaxImgLength'=>'1200px') as $key=>$value)
			{
				if(!array_key_exists($key,$options))
					$options[$key]=$value;
			}
		}
		$this->_purifier=new HTMLPurifier($options);
		$this->_purifier->config->set('Cache.SerializerPath',Yii::app()->getRuntimePath());
		self::adjustImgLengths($this->_purifier->config);
		return $this->_purifier;
	}

	/**
	 * Changes how the CSS width and height of an img are validated when CSS.MaxImgLength is set. HTMLPurifier then
	 * accepts only a length up to that limit or 'auto' there, and drops anything else. With this change:
	 * <ul>
	 * <li>a percentage from 0% to 100% is kept: rich-text editors write style="width:50%" for an image sized
	 *   relative to its container, and such a percentage cannot make the image wider than its container;</li>
	 * <li>a width over the limit becomes the limit (width:2000px becomes width:1200px), and the image's height,
	 *   in the style and as an attribute, is removed, so the browser keeps the image's proportions.</li>
	 * </ul>
	 * A height over the limit, a percentage over 100% and a length in a unit that has no fixed size (em, ex, rem,
	 * ch, vw, vh and the like) are still dropped. A property that CSS.AllowedProperties or CSS.ForbiddenProperties removed stays removed.
	 *
	 * The change is made on the configuration's loaded CSS and HTML definitions, not on the cached ones. Loading
	 * them finalizes the configuration: set every directive, 'Cache.SerializerPath' included, before the call.
	 * @param HTMLPurifier_Config $config the configuration to change
	 * @since 1.1.32.5 (d3sw)
	 */
	public static function adjustImgLengths($config)
	{
		$max=$config->get('CSS.MaxImgLength');
		if($max===null)
			return;
		$definition=$config->getCSSDefinition();
		// as the definition does for its own rules, so that "50% !important" is read as 50%
		$important=$config->get('CSS.AllowImportant');
		// the definition's own rule still validates everything else, for img and for the other elements
		if(isset($definition->info['height']))
		{
			$own=$definition->info['height'];
			$definition->info['height']=new HTMLPurifier_AttrDef_Switch('img',
				new HTMLPurifier_AttrDef_CSS_ImportantDecorator(new CHtmlPurifierImgLength($own),$important),$own);
		}
		if(!isset($definition->info['width']))
			return;
		$html=$config->getHTMLDefinition();
		$limited=null;
		if(isset($html->info['img']))
		{
			$limited=new CHtmlPurifierImgWidthLimited($max);
			$html->info['img']->attr_transform_post[]=$limited;
		}
		$own=$definition->info['width'];
		$definition->info['width']=new HTMLPurifier_AttrDef_Switch('img',
			new HTMLPurifier_AttrDef_CSS_ImportantDecorator(new CHtmlPurifierImgLength($own,$limited),$important),$own);
	}
}

/**
 * CHtmlPurifierImgLength validates the CSS width or height of an img under CSS.MaxImgLength: what the CSS
 * definition's own rule accepts, a percentage from 0% to 100% and, for a width, a length over the limit as the limit.
 *
 * @package system.web.widgets
 * @since 1.1.32.5 (d3sw)
 */
class CHtmlPurifierImgLength extends HTMLPurifier_AttrDef
{
	/**
	 * @var HTMLPurifier_AttrDef the CSS definition's own rule for the property
	 */
	protected $own;
	/**
	 * @var CHtmlPurifierImgWidthLimited|null what turns a length over the limit into the limit; null drops it
	 */
	protected $limited;
	/**
	 * @var HTMLPurifier_AttrDef_CSS_Percentage
	 */
	protected $percentage;

	/**
	 * @param HTMLPurifier_AttrDef $own the CSS definition's own rule for the property
	 * @param CHtmlPurifierImgWidthLimited|null $limited for a width: the img transform that removes the height
	 * of an image whose width was limited. Null (a height) drops a length over the limit.
	 */
	public function __construct($own,$limited=null)
	{
		$this->own=$own;
		$this->limited=$limited;
		$this->percentage=new HTMLPurifier_AttrDef_CSS_Percentage(true);
	}

	/**
	 * @param string $string
	 * @param HTMLPurifier_Config $config
	 * @param HTMLPurifier_Context $context
	 * @return bool|string
	 */
	public function validate($string,$config,$context)
	{
		$result=$this->own->validate($string,$config,$context);
		if($result!==false)
			return $result;
		$result=$this->percentage->validate($string,$config,$context);
		if($result!==false)
			return (float)$result>100 ? false : $result;
		if($this->limited===null)
			return false;
		return $this->limited->limit($this->parseCDATA($string),$context);
	}
}

/**
 * CHtmlPurifierImgWidthLimited turns a CSS width over CSS.MaxImgLength into the limit and, as a transform that
 * runs after an img's attributes are validated, removes the height of that image from its style and its height
 * attribute: a limited width with the height left as it was would change the image's proportions.
 *
 * @package system.web.widgets
 * @since 1.1.32.6 (d3sw)
 */
class CHtmlPurifierImgWidthLimited extends HTMLPurifier_AttrTransform
{
	/**
	 * @var HTMLPurifier_Length the limit
	 */
	protected $max;
	/**
	 * @var HTMLPurifier_Token|null the img whose width was limited, until its transform has run
	 */
	protected $token;

	/**
	 * @param string $max the limit, the value of CSS.MaxImgLength
	 */
	public function __construct($max)
	{
		$this->max=HTMLPurifier_Length::make($max);
	}

	/**
	 * @param string $string the width, already trimmed
	 * @param HTMLPurifier_Context $context
	 * @return bool|string the limit when the width is a length over it, false otherwise
	 */
	public function limit($string,$context)
	{
		if(strlen($string)<2)
			return false;
		$length=HTMLPurifier_Length::make($string);
		// compareTo() is false for a unit that has no fixed size (em, ex, rem, vw and the like)
		if(!$length->isValid() || !($length->compareTo($this->max)>0))
			return false;
		$this->token=$context->get('CurrentToken',true);
		return $this->max->toString();
	}

	/**
	 * @param array $attr
	 * @param HTMLPurifier_Config $config
	 * @param HTMLPurifier_Context $context
	 * @return array
	 */
	public function transform($attr,$config,$context)
	{
		$token=$this->token;
		$this->token=null;
		if($token===null || $token!==$context->get('CurrentToken',true))
			return $attr;
		unset($attr['height']);
		if(isset($attr['style']))
		{
			// a validated style is a list of "property:value;"
			$kept='';
			foreach(explode(';',$attr['style']) as $declaration)
			{
				if($declaration!=='' && strncmp($declaration,'height:',7)!==0)
					$kept.=$declaration.';';
			}
			$attr['style']=$kept;
		}
		return $attr;
	}
}
