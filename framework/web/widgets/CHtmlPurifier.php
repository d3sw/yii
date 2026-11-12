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
		self::allowImgPercentLengths($this->_purifier->config);
		return $this->_purifier;
	}

	/**
	 * Lets the CSS width and height of an img be a percentage from 0% to 100% when CSS.MaxImgLength is set.
	 * HTMLPurifier then accepts only a length up to that limit or 'auto' there, so it drops style="width:50%", which
	 * rich-text editors write when an image is sized relative to its container. A percentage of at most 100%
	 * cannot make the image wider than its container, so the limit keeps its purpose (d3sw 1.1.32.4).
	 *
	 * The change is made on the configuration's loaded CSS definition, not on the cached one. Loading the
	 * definition finalizes the configuration: set every directive, 'Cache.SerializerPath' included, before the call.
	 * @param HTMLPurifier_Config $config the configuration to change
	 */
	public static function allowImgPercentLengths($config)
	{
		$max=$config->get('CSS.MaxImgLength');
		if($max===null)
			return;
		$definition=$config->getCSSDefinition();
		// the definition's own rule still validates everything else, for img and for the other elements
		$own=$definition->info['width'];
		$definition->info['width']=$definition->info['height']=new HTMLPurifier_AttrDef_Switch(
			'img',
			new HTMLPurifier_AttrDef_CSS_Composite(array(new CHtmlPurifierImgPercentage(),$own)),
			$own
		);
	}
}

/**
 * CHtmlPurifierImgPercentage validates a CSS percentage from 0% to 100% for the width and height of an img.
 *
 * @package system.web.widgets
 * @since 1.1.32.4 (d3sw)
 */
class CHtmlPurifierImgPercentage extends HTMLPurifier_AttrDef_CSS_Percentage
{
	public function __construct()
	{
		parent::__construct(true);
	}

	/**
	 * @param string $string
	 * @param HTMLPurifier_Config $config
	 * @param HTMLPurifier_Context $context
	 * @return bool|string
	 */
	public function validate($string,$config,$context)
	{
		$result=parent::validate($string,$config,$context);
		if($result===false || (float)$result>100)
			return false;
		return $result;
	}
}
