/* eslint-env node */
/* jshint node:true */

module.exports = function( grunt ) {
	'use strict';

	grunt.initConfig( {

		pkg: grunt.file.readJSON( 'package.json' ),

		// JavaScript linting with JSHint.
		jshint: {
			options: {
				jshintrc: '.jshintrc'
			},
			all: [
				'Gruntfile.js',
				'*.js'
			]
		},

		// Minify .js files.
		uglify: {
			options: {
				preserveComments: false
			},
			core: {
				files: [ {
					expand: true,
					cwd: 'js/',
					src: [
						'*.js',
						'!*.min.js'
					],
					dest: 'js/',
					ext: '.min.js'
				} ]
			}
		},

		// Minify .css files.
		cssmin: {
			core: {
				files: [ {
					expand: true,
					cwd: 'css/',
					src: [
						'*.css',
						'!*.min.css'
					],
					dest: 'css/',
					ext: '.min.css'
				} ]
			}
		},

		// Build a deploy-able plugin
		copy: {
			build: {
				src: [
					'*.php',
					'php/*.php',
					'js/*.js',
					'css/*.css',
					'bower_components/select2/dist/js/select2.full.js',
					'bower_components/select2/dist/js/select2.full.min.js',
					'bower_components/select2/dist/css/select2.css',
					'bower_components/select2/dist/css/select2.min.css',
					'bower_components/select2/dist/js/i18n/*.js',
					'readme.txt'
				],
				dest: 'build',
				expand: true,
				dot: true
			}
		},

		// Clean up the build
		clean: {
			build: {
				src: [ 'build' ]
			}
		},

		// VVV (Varying Vagrant Vagrants) Paths
		vvv: {
			'plugin': '/srv/www/wordpress-develop/src/wp-content/plugins/<%= pkg.name %>',
			'coverage': '/srv/www/default/coverage/<%= pkg.name %>'
		},

		// Shell actions
		shell: {
			options: {
				stdout: true,
				stderr: true
			},
			readme: {
				command: 'cd ./dev-lib && ./generate-markdown-readme' // Generate the readme.md
			},
			phpunit: {
				command: 'vagrant ssh -c "cd <%= vvv.plugin %> && phpunit"'
			},
			phpunit_c: {
				command: 'vagrant ssh -c "cd <%= vvv.plugin %> && phpunit --coverage-html <%= vvv.coverage %>"'
			}
		},

		// Deploys a git Repo to the WordPress SVN repo
		wp_deploy: {
			deploy: {
				options: {
					plugin_slug: '<%= pkg.name %>',
					build_dir: 'build',
					assets_dir: 'wp-assets'
				}
			}
		}

	} );

	// Load tasks
	grunt.loadNpmTasks( 'grunt-contrib-clean' );
	grunt.loadNpmTasks( 'grunt-contrib-copy' );
	grunt.loadNpmTasks( 'grunt-contrib-cssmin' );
	grunt.loadNpmTasks( 'grunt-contrib-jshint' );
	grunt.loadNpmTasks( 'grunt-contrib-uglify' );
	grunt.loadNpmTasks( 'grunt-shell' );
	grunt.loadNpmTasks( 'grunt-wp-deploy' );

	// Register tasks
	grunt.registerTask( 'default', [
		'build'
	] );

	grunt.registerTask( 'readme', [
		'shell:readme'
	] );

	grunt.registerTask( 'phpunit', [
		'shell:phpunit'
	] );

	grunt.registerTask( 'phpunit_c', [
		'shell:phpunit_c'
	] );

	grunt.registerTask( 'dev', [
		'default',
		'readme'
	] );

	grunt.registerTask( 'build', [
		'jshint',
		'uglify',
		'cssmin',
		'readme',
		'copy'
	] );

	grunt.registerTask( 'deploy', [
		'build',
		'wp_deploy',
		'clean'
	] );

};
